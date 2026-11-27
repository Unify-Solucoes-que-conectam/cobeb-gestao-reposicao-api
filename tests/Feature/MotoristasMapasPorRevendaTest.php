<?php

namespace Tests\Feature;

use App\Imports\GenericImport;
use App\Models\Cliente;
use App\Models\ClientesMapa;
use App\Models\Filial;
use App\Models\ImportBatch;
use App\Models\Mapa;
use App\Models\Motorista;
use App\Models\NotaFiscal;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MotoristasMapasPorRevendaTest extends TestCase
{
    use RefreshDatabase;

    private Filial $lp;
    private Filial $pm;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->lp = Filial::create(['codigo' => '1', 'descricao' => 'LP']);
        $this->pm = Filial::create(['codigo' => '2', 'descricao' => 'PM']);
    }

    private function importar(string $type, array $records): void
    {
        $batch = ImportBatch::create([
            'type' => $type, 'status' => 'processing', 'total_rows' => count($records),
            'processed_rows' => 0, 'percentage' => 0, 'last_log' => '', 'current_step' => 'importing',
        ]);
        $import = new GenericImport($batch->id, $type, count($records));
        $import->processRecords($records);
        $this->assertSame([], $import->getRowErrors());
    }

    private function motoristaRow(string $cpf, string $codigo, string $nome, string $filial): array
    {
        return [
            'cpf' => $cpf, 'codmotorista' => $codigo, 'nome_motorista' => $nome,
            'codcluster' => 'C1', 'cluster' => 'Cluster 1', 'codfilial' => $filial, 'status' => 'ativo',
        ];
    }

    private function clienteRow(string $codigo, string $nome, string $filial): array
    {
        return ['cod_pdv' => $codigo, 'nome_fantasia' => $nome, 'razao_social' => $nome, 'filial' => $filial, 'status' => 'ativo', 'tipo_pessoa' => 'juridica'];
    }

    public function test_importar_base_de_uma_revenda_nao_sobrescreve_a_outra(): void
    {
        $this->importar('motoristas', [$this->motoristaRow('11111111111', '100', 'Motorista LP', '1')]);
        $this->importar('motoristas', [$this->motoristaRow('22222222222', '100', 'Motorista PM', '2')]);

        $this->assertSame(2, Motorista::where('codigo', '100')->count());
        $this->assertTrue(Motorista::where('codigo', '100')->where('filial_id', $this->lp->id)->exists());
        $this->assertTrue(Motorista::where('codigo', '100')->where('filial_id', $this->pm->id)->exists());

        $this->importar('clientes', [$this->clienteRow('500', 'Cliente LP', '1')]);
        $this->importar('clientes', [$this->clienteRow('500', 'Cliente PM', '2')]);

        $this->assertSame(2, Cliente::where('codigo', '500')->count());
    }

    public function test_reimportar_mapa_substitui_motorista_e_clientes_usando_a_revenda_do_mapa(): void
    {
        $this->importar('motoristas', [
            $this->motoristaRow('11111111111', '100', 'Motorista LP', '1'),
            $this->motoristaRow('22222222222', '100', 'Motorista PM', '2'),
            $this->motoristaRow('33333333333', '200', 'Outro PM', '2'),
        ]);
        $this->importar('clientes', [
            $this->clienteRow('500', 'A LP', '1'),
            $this->clienteRow('500', 'A PM', '2'),
            $this->clienteRow('600', 'B PM', '2'),
        ]);

        $mapaRow = ['nro_do_mapa' => '9000', 'unb' => '2', 'motorista' => '100', 'data_entrega' => now()->format('d/m/Y'), 'placa' => 'AAA1234', 'clientes' => '500/600'];
        $this->importar('mapas', [$mapaRow]);

        $mapa = Mapa::where('codigo', '9000')->firstOrFail();
        $this->assertSame($this->pm->id, $mapa->motorista->filial_id);
        $this->assertEqualsCanonicalizing(['A PM', 'B PM'], $mapa->clientes()->with('cliente')->get()->pluck('cliente.nome_fantasia')->all());

        // novo arquivo: troca motorista e remove um cliente
        $this->importar('mapas', [array_merge($mapaRow, ['motorista' => '200', 'clientes' => '600'])]);

        $mapa->refresh();
        $this->assertSame('200', $mapa->motorista->codigo);
        $this->assertSame(1, ClientesMapa::where('mapa_id', $mapa->id)->count());
        $this->assertSame(1, Mapa::count());
    }

    public function test_motorista_designado_pode_ser_trocado_e_clientes_alterados(): void
    {
        $admin = Usuario::create(['nome' => 'Admin', 'cpf' => '99999999999', 'senha' => 'x', 'role' => 'monitoramento']);

        $this->importar('motoristas', [
            $this->motoristaRow('11111111111', '100', 'Atual', '2'),
            $this->motoristaRow('22222222222', '200', 'Novo', '2'),
            $this->motoristaRow('33333333333', '300', 'Outra revenda', '1'),
        ]);
        $this->importar('clientes', [$this->clienteRow('500', 'A', '2'), $this->clienteRow('600', 'B', '2'), $this->clienteRow('700', 'C LP', '1')]);
        $this->importar('mapas', [['nro_do_mapa' => '9000', 'unb' => '2', 'motorista' => '100', 'data_entrega' => now()->format('d/m/Y'), 'placa' => 'AAA1234', 'clientes' => '500']]);

        $mapa = Mapa::firstOrFail();
        $novo = Motorista::where('codigo', '200')->firstOrFail();
        $outraRevenda = Motorista::where('codigo', '300')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/mapas/{$mapa->id}/designar-motorista/{$novo->id}")
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame($novo->id, $mapa->fresh()->motorista_id);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/mapas/{$mapa->id}/designar-motorista/{$outraRevenda->id}")
            ->assertStatus(422);

        $b = Cliente::where('codigo', '600')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/mapas/{$mapa->id}/clientes", ['clientes' => [$b->id]])
            ->assertOk()->assertJsonPath('data.clientes.0.id', $b->id);
        $this->assertSame([$b->id], ClientesMapa::where('mapa_id', $mapa->id)->pluck('cliente_id')->all());

        $clienteLp = Cliente::where('codigo', '700')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/mapas/{$mapa->id}/clientes", ['clientes' => [$clienteLp->id]])
            ->assertStatus(422);
    }

    public function test_importar_motoristas_nao_reseta_senha_ja_alterada(): void
    {
        $this->importar('motoristas', [$this->motoristaRow('11111111111', '100', 'Motorista', '1')]);

        $usuario = Usuario::where('cpf', '11111111111')->firstOrFail();
        $senhaInicial = $usuario->senha;
        $usuario->update(['senha' => 'NovaSenha@123', 'primeiro_acesso' => false]);
        $senhaAlterada = $usuario->fresh()->senha;
        $this->assertNotSame($senhaInicial, $senhaAlterada);

        $this->importar('motoristas', [$this->motoristaRow('11111111111', '100', 'Motorista Renomeado', '1')]);

        $usuario->refresh();
        $this->assertSame('MOTORISTA RENOMEADO', mb_strtoupper($usuario->nome));
        $this->assertFalse((bool) $usuario->primeiro_acesso);
        $this->assertSame($senhaAlterada, $usuario->senha);
    }

    public function test_mesmo_numero_de_nota_em_filiais_diferentes(): void
    {
        $this->importar('clientes', [$this->clienteRow('500', 'Cliente LP', '1'), $this->clienteRow('600', 'Cliente PM', '2')]);
        Produto::create(['codigo' => 'P1', 'descricao' => 'Produto']);

        $entrada = fn (string $cliente) => [
            'nota_fiscal' => '123', 'nr_pedido' => '1', 'cliente' => $cliente, 'dt_operacao' => now()->format('d/m/Y'),
            'operacao' => '1', 'emissao' => now()->format('d/m/Y'), 'produto' => 'P1', 'quantidade' => '10', 'unidade_medida' => 'CX',
            'desconto' => '0', 'adic_fina' => '0', 'total' => '10',
        ];
        $this->importar('vendas_trocas', [$entrada('500'), $entrada('600')]);

        $notas = NotaFiscal::where('numero', '123')->get();
        $this->assertCount(2, $notas);
        $this->assertEqualsCanonicalizing([$this->lp->id, $this->pm->id], $notas->pluck('filial_id')->all());
    }
}
