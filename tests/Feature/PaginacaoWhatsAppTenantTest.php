<?php

namespace Tests\Feature;

use App\Models\Filial;
use App\Models\Motorista;
use App\Models\Usuario;
use App\Models\WhatsAppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginacaoWhatsAppTenantTest extends TestCase
{
    use RefreshDatabase;

    private Filial $matriz;
    private Filial $abaete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matriz = Filial::create(['codigo' => '1', 'descricao' => 'Matriz']);
        $this->abaete = Filial::create(['codigo' => '2', 'descricao' => 'Abaeté']);
    }

    private function usuario(string $role, bool $geral, array $filiais = []): Usuario
    {
        $u = Usuario::create(['nome' => $role, 'cpf' => (string) random_int(10000000000, 99999999999), 'senha' => 'x', 'role' => $role]);
        $u->acesso_geral = $geral;
        $u->save();
        $u->filiais()->sync($filiais);

        return $u->fresh();
    }

    public function test_listagem_de_motoristas_e_paginada_quando_informado_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            $u = Usuario::create(['nome' => "M{$i}", 'cpf' => str_pad((string) $i, 11, '0', STR_PAD_LEFT), 'senha' => 'x', 'role' => 'motorista']);
            Motorista::create(['codigo' => (string) $i, 'filial_id' => $this->matriz->id, 'usuario_id' => $u->id]);
        }
        $admin = $this->usuario('administrador', true);

        $this->actingAs($admin, 'sanctum')->getJson('/motoristas?per_page=10&page=3')
            ->assertOk()
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonCount(5, 'data');

        // sem per_page: lista completa (seletores)
        $this->actingAs($admin, 'sanctum')->getJson('/motoristas')->assertOk()->assertJsonCount(25, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/usuarios?per_page=10')->assertOk()->assertJsonPath('pagination.per_page', 10);
        $this->actingAs($admin, 'sanctum')->getJson('/mapas?per_page=10')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_whatsapp_fora_do_geral_so_acessa_a_filial_conectada(): void
    {
        $usuario = $this->usuario('administrador', false);
        // administrador sempre tem Geral; para simular uma filial usa-se o header
        $req = fn () => $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', $this->abaete->id);

        $lista = $req()->getJson('/whatsapp/configurations')->assertOk()->json('data');
        $this->assertSame([$this->abaete->id], array_column(array_column($lista, 'filial'), 'id'));

        $req()->getJson("/whatsapp/configurations/{$this->matriz->id}")->assertForbidden();
        $req()->putJson("/whatsapp/configurations/{$this->abaete->id}/global", ['enabled' => true])->assertForbidden();

        // no Geral, outras filiais ficam liberadas
        $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', 'geral')
            ->getJson("/whatsapp/configurations/{$this->matriz->id}")->assertOk();
    }

    public function test_instancia_global_so_pode_ser_alterada_no_geral(): void
    {
        WhatsAppConfiguration::query()->create([
            'filial_id' => $this->matriz->id, 'provider' => 'baileys', 'instance_name' => 'global',
            'status' => 'connected', 'is_global' => true, 'global_slot' => 'global',
        ]);
        $usuario = $this->usuario('administrador', false);

        $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', $this->abaete->id)
            ->postJson("/whatsapp/configurations/{$this->abaete->id}/reconnect")
            ->assertForbidden()
            ->assertJsonPath('code', 'WHATSAPP_TENANT_FORBIDDEN');
    }
}
