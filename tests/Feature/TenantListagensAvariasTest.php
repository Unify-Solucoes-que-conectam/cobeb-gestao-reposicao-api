<?php

namespace Tests\Feature;

use App\Models\Avaria;
use App\Models\Cliente;
use App\Models\Filial;
use App\Models\Grupo;
use App\Models\Mapa;
use App\Models\Motorista;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantListagensAvariasTest extends TestCase
{
    use RefreshDatabase;

    private Filial $matriz;
    private Filial $abaete;
    private Motorista $motoristaMatriz;
    private Motorista $motoristaAbaete;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->matriz = Filial::create(['codigo' => '1', 'descricao' => 'Matriz']);
        $this->abaete = Filial::create(['codigo' => '2', 'descricao' => 'Abaeté']);

        foreach ([[$this->matriz, 'motoristaMatriz'], [$this->abaete, 'motoristaAbaete']] as [$filial, $prop]) {
            $usuario = Usuario::create(['nome' => 'M', 'cpf' => (string) random_int(10000000000, 99999999999), 'senha' => 'x', 'role' => 'motorista']);
            $this->{$prop} = Motorista::create(['codigo' => '10', 'filial_id' => $filial->id, 'usuario_id' => $usuario->id]);
            $cliente = Cliente::create(['codigo' => '5', 'nome_fantasia' => 'C', 'filial_id' => $filial->id]);
            Mapa::create(['codigo' => '99', 'filial_id' => $filial->id, 'data_entrega' => now()->toDateString(), 'placa' => 'X']);
            Avaria::create(['cliente_id' => $cliente->id, 'motorista_id' => $this->{$prop}->id, 'status' => 'aguardando_aprovacao', 'data_emissao' => now()->toDateString()]);
        }
    }

    private function usuario(string $role, bool $geral, array $filiais = []): Usuario
    {
        $u = Usuario::create(['nome' => $role, 'cpf' => (string) random_int(10000000000, 99999999999), 'senha' => 'x', 'role' => $role]);
        $u->acesso_geral = $geral;
        $u->save();
        $u->filiais()->sync($filiais);

        return $u->fresh();
    }

    public function test_numa_filial_as_listagens_ignoram_o_filtro_e_mostram_so_a_filial_conectada(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);
        $req = fn (string $url) => $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', $this->abaete->id)->getJson($url)->assertOk()->json('data');

        // mesmo pedindo a Matriz, só vem Abaeté
        $this->assertSame([$this->abaete->id], array_unique(array_column($req("/mapas?filial={$this->matriz->id}"), 'filial') ? array_column(array_column($req("/mapas?filial={$this->matriz->id}"), 'filial'), 'id') : []));
        $this->assertSame([$this->motoristaAbaete->id], array_column($req("/motoristas?filial={$this->matriz->id}"), 'id'));
        $this->assertCount(1, $req("/clientes?filial={$this->matriz->id}"));
        $this->assertCount(1, $req("/avarias?filialId={$this->matriz->id}"));
    }

    public function test_no_geral_o_filtro_de_filial_funciona(): void
    {
        $usuario = $this->usuario('monitoramento', true);
        $req = fn (string $url) => $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', 'geral')->getJson($url)->assertOk()->json('data');

        $this->assertCount(2, $req('/motoristas'));
        $this->assertSame([$this->motoristaMatriz->id], array_column($req("/motoristas?filial={$this->matriz->id}"), 'id'));
        $this->assertCount(2, $req('/avarias'));
        $this->assertCount(1, $req("/avarias?filialId={$this->matriz->id}"));
    }

    public function test_aprovar_avaria_exige_acao_de_aprovar(): void
    {
        $avaria = Avaria::firstOrFail();
        $motorista = $this->motoristaMatriz->usuario;

        // motorista pode enviar para aprovação, mas não aprovar
        $this->actingAs($motorista, 'sanctum')->putJson("/avarias/{$avaria->id}/status", ['status' => 'aprovada'])->assertForbidden();
        // (a permissão libera; o restante do fluxo de notificação não faz parte deste teste)
        $this->assertNotSame(403, $this->actingAs($motorista, 'sanctum')->putJson("/avarias/{$avaria->id}/status", ['status' => 'aguardando_aprovacao'])->status());

        // grupo de monitoramento sem a ação de aprovar
        $grupo = Grupo::create(['nome' => 'Somente consulta', 'perfil' => 'monitoramento']);
        $consulta = $this->usuario('monitoramento', true);
        $consulta->update(['grupo_id' => $grupo->id]);
        $this->actingAs($consulta->fresh(), 'sanctum')->putJson("/avarias/{$avaria->id}/status", ['status' => 'aprovada'])->assertForbidden();

        // monitoramento padrão aprova, mas não registra avarias
        $monitoramento = $this->usuario('monitoramento', true);
        $this->actingAs($monitoramento, 'sanctum')->putJson("/avarias/{$avaria->id}/status", ['status' => 'aprovada'])->assertOk();
        $this->actingAs($monitoramento, 'sanctum')->deleteJson("/avarias/{$avaria->id}")->assertForbidden();
    }

    public function test_numa_filial_nao_cadastra_motorista_de_outra_filial(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);

        $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', $this->abaete->id)
            ->postJson('/motoristas', ['nome' => 'Novo', 'cpf' => '12312312312', 'codigo' => '77', 'filial_id' => $this->matriz->id])
            ->assertStatus(422);
    }
}
