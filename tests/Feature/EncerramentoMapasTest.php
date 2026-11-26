<?php

namespace Tests\Feature;

use App\Imports\GenericImport;
use App\Models\Filial;
use App\Models\ImportBatch;
use App\Models\Mapa;
use App\Models\Motorista;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Rotas (mapas) valem só no dia da designação e são encerradas às 00h. */
class EncerramentoMapasTest extends TestCase
{
    use RefreshDatabase;

    private Motorista $motorista;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-26 15:00:00', 'America/Sao_Paulo'));

        Filial::create(['codigo' => '1', 'descricao' => 'LP']);

        $this->importar('motoristas', [[
            'cpf' => '11111111111', 'codmotorista' => '100', 'nome_motorista' => 'Motorista',
            'codcluster' => 'C1', 'cluster' => 'Cluster 1', 'codfilial' => '1', 'status' => 'ativo',
        ]]);
        $this->importar('clientes', [[
            'cod_pdv' => '500', 'nome_fantasia' => 'Cliente', 'razao_social' => 'Cliente',
            'filial' => '1', 'status' => 'ativo', 'tipo_pessoa' => 'juridica',
        ]]);

        $this->motorista = Motorista::where('codigo', '100')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function importarMapa(string $dataEntrega): Mapa
    {
        $this->importar('mapas', [[
            'nro_do_mapa' => '9000', 'unb' => '1', 'motorista' => '100',
            'data_entrega' => $dataEntrega, 'placa' => 'AAA1234', 'clientes' => '500',
        ]]);

        return Mapa::where('codigo', '9000')->firstOrFail();
    }

    private function mapaDoMotorista(): ?string
    {
        return $this->actingAs($this->motorista->usuario, 'sanctum')
            ->getJson('/auth/me')->assertOk()
            ->json('data.usuario.motorista.mapa.id');
    }

    private function clientesDoMotorista(Mapa $mapa): array
    {
        return $this->actingAs($this->motorista->usuario, 'sanctum')
            ->getJson("/mapas/{$mapa->id}/clientes")->assertOk()
            ->json('data');
    }

    public function test_mapa_designado_hoje_fica_disponivel_ate_meia_noite(): void
    {
        $mapa = $this->importarMapa('26/11/2026');

        $this->assertSame('em_andamento', $mapa->status);
        $this->assertSame($mapa->id, $this->mapaDoMotorista());
        $this->assertCount(1, $this->clientesDoMotorista($mapa));

        Carbon::setTestNow(Carbon::parse('2026-11-26 23:59:00', 'America/Sao_Paulo'));
        $this->assertSame($mapa->id, $this->mapaDoMotorista());
    }

    public function test_as_00h_a_rota_e_encerrada_e_mantem_o_historico(): void
    {
        $mapa = $this->importarMapa('26/11/2026');

        Carbon::setTestNow(Carbon::parse('2026-11-27 00:00:00', 'America/Sao_Paulo'));
        $this->artisan('mapas:encerrar')->assertSuccessful();

        $mapa->refresh();
        $this->assertNotNull($mapa->encerrado_em);
        $this->assertSame($this->motorista->id, $mapa->motorista_id); // histórico preservado
        $this->assertSame('encerrado', $mapa->status);
        $this->assertNull($this->mapaDoMotorista());
        $this->assertCount(0, $this->clientesDoMotorista($mapa));
    }

    public function test_mapa_designado_ontem_para_hoje_nao_vale_mesmo_sem_a_rotina(): void
    {
        // designado no dia 26 com entrega no dia 27
        $mapa = $this->importarMapa('27/11/2026');
        $this->assertNull($this->mapaDoMotorista()); // entrega não é hoje

        // dia 27: a designação foi ontem, então a rota já está encerrada (mesmo sem rodar o comando)
        Carbon::setTestNow(Carbon::parse('2026-11-27 08:00:00', 'America/Sao_Paulo'));
        $this->assertNull($this->mapaDoMotorista());
        $this->assertCount(0, $this->clientesDoMotorista($mapa));
    }

    public function test_designar_novamente_reabre_a_rota_no_dia(): void
    {
        $mapa = $this->importarMapa('27/11/2026');

        Carbon::setTestNow(Carbon::parse('2026-11-27 00:00:00', 'America/Sao_Paulo'));
        $this->artisan('mapas:encerrar')->assertSuccessful();
        $this->assertSame('encerrado', $mapa->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-11-27 07:00:00', 'America/Sao_Paulo'));
        $admin = Usuario::create(['nome' => 'Admin', 'cpf' => '99999999999', 'senha' => 'x', 'role' => 'monitoramento']);
        $this->actingAs($admin, 'sanctum')
            ->putJson("/mapas/{$mapa->id}/designar-motorista/{$this->motorista->id}")
            ->assertOk()->assertJsonPath('data.status', 'em_andamento');

        $this->assertSame($mapa->id, $this->mapaDoMotorista());
        $this->assertCount(1, $this->clientesDoMotorista($mapa));
    }

    public function test_mapa_sem_motorista_com_entrega_vencida_fica_encerrado(): void
    {
        $vencido = Mapa::create(['codigo' => '7000', 'data_entrega' => '2026-11-25', 'placa' => 'BBB1234']);
        $deHoje = Mapa::create(['codigo' => '7001', 'data_entrega' => '2026-11-26', 'placa' => 'CCC1234']);

        $this->assertSame('encerrado', $vencido->status);
        $this->assertSame('nao_designado', $deHoje->status);

        $this->artisan('mapas:encerrar')->assertSuccessful();
        $this->assertNotNull($vencido->fresh()->encerrado_em);
        $this->assertNull($deHoje->fresh()->encerrado_em);
    }
}
