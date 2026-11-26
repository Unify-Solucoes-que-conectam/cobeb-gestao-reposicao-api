<?php

namespace Tests\Feature;

use App\Models\Filial;
use App\Models\Grupo;
use App\Models\Menu;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantsPermissoesTest extends TestCase
{
    use RefreshDatabase;

    private Filial $matriz;
    private Filial $abaete;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake();

        $this->matriz = Filial::create(['codigo' => '1', 'descricao' => 'Matriz']);
        $this->abaete = Filial::create(['codigo' => '2', 'descricao' => 'Abaeté']);
    }

    private function usuario(string $role, bool $geral = false, array $filiais = []): Usuario
    {
        $usuario = Usuario::create(['nome' => $role, 'cpf' => (string) random_int(10000000000, 99999999999), 'senha' => 'Senha@123', 'role' => $role]);
        $usuario->acesso_geral = $geral;
        $usuario->save();
        $usuario->filiais()->sync($filiais);

        return $usuario->fresh();
    }

    private function registrosImportados(): array
    {
        $arquivo = Storage::allFiles('imports')[0];

        return json_decode(Storage::get($arquivo), true);
    }

    private function motorista(string $codigo, string $filial = ''): array
    {
        return ['cpf' => '11111111111', 'codmotorista' => $codigo, 'nome_motorista' => 'M', 'codcluster' => 'C', 'cluster' => 'C', 'codfilial' => $filial];
    }

    public function test_fora_do_geral_a_filial_conectada_e_injetada_nos_registros(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);

        $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Tenant', $this->abaete->id)
            ->postJson('/importar', ['type' => 'motoristas', 'records' => [$this->motorista('100'), $this->motorista('200', '02')]])
            ->assertOk();

        $this->assertSame(['2', '2'], array_column($this->registrosImportados(), 'codfilial'));
    }

    public function test_registro_de_outra_filial_e_recusado(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);

        $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Tenant', $this->abaete->id)
            ->postJson('/importar', ['type' => 'motoristas', 'records' => [$this->motorista('100', '1')]])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame([], Storage::allFiles('imports'));
    }

    public function test_no_geral_cada_registro_mantem_a_propria_filial(): void
    {
        $usuario = $this->usuario('monitoramento', true);

        $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Tenant', 'geral')
            ->postJson('/importar', ['type' => 'motoristas', 'records' => [$this->motorista('100', '1'), $this->motorista('200', '2')]])
            ->assertOk();

        $this->assertSame(['1', '2'], array_column($this->registrosImportados(), 'codfilial'));
    }

    public function test_tenant_sem_acesso_e_bloqueado(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);

        $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', 'geral')->getJson('/mapas')->assertForbidden();
        $this->actingAs($usuario, 'sanctum')->withHeader('X-Tenant', $this->matriz->id)->getJson('/mapas')->assertForbidden();
    }

    public function test_grupo_sem_a_acao_nao_importa(): void
    {
        $grupo = Grupo::create(['nome' => 'Consulta', 'perfil' => 'monitoramento']);
        $usuario = $this->usuario('monitoramento', true);
        $usuario->update(['grupo_id' => $grupo->id]);

        $this->actingAs($usuario->fresh(), 'sanctum')
            ->postJson('/importar', ['type' => 'motoristas', 'records' => [$this->motorista('100', '1')]])
            ->assertForbidden();
    }

    public function test_login_retorna_menus_do_grupo_permissoes_e_tenants(): void
    {
        $usuario = $this->usuario('monitoramento', false, [$this->abaete->id]);

        $data = $this->postJson('/auth/login', ['cpf' => $usuario->cpf, 'senha' => 'Senha@123'])->assertOk()->json('data');

        $this->assertSame([['id' => $this->abaete->id, 'nome' => 'Abaeté', 'codigo' => '2', 'geral' => false]], $data['tenants']);
        $this->assertContains('importacoes.importar', $data['permissoes']);
        $this->assertNotContains('permissoes.gerenciar', $data['permissoes']);

        $rotas = collect($data['menus'])->flatMap(fn ($m) => array_merge([$m['rota']], array_column($m['sub_menus'], 'rota')));
        $this->assertNotContains('/admin/gerenciar/permissoes', $rotas);
    }

    public function test_administrador_configura_grupo_e_acessos_do_usuario(): void
    {
        $admin = $this->usuario('administrador');
        $usuario = $this->usuario('monitoramento', true);
        $mapas = Menu::where('rota', '/admin/gerenciar/mapas')->first();

        $grupoId = $this->actingAs($admin, 'sanctum')
            ->postJson('/permissoes/grupos', [
                'nome' => 'Mapas Abaeté', 'perfil' => 'monitoramento',
                'menus' => $mapas ? [$mapas->id] : [], 'permissoes' => ['mapas.designar_motorista'],
            ])
            ->assertOk()->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/permissoes/usuarios/{$usuario->id}", ['grupo_id' => $grupoId, 'acesso_geral' => false, 'filiais' => [$this->abaete->id]])
            ->assertOk();

        $usuario = $usuario->fresh();
        $this->assertSame(['mapas.designar_motorista'], $usuario->permissoes());
        $this->assertFalse($usuario->temAcessoGeral());
        $this->assertSame([$this->abaete->id], $usuario->filiais->pluck('id')->all());

        // quem não tem a ação de gerenciar permissões não acessa o menu
        $this->actingAs($usuario, 'sanctum')->getJson('/permissoes/grupos')->assertForbidden();
    }
}
