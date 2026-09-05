<?php

namespace Tests\Feature;

use App\Models\Filial;
use App\Models\Usuario;
use App\Models\WhatsAppConfiguration;
use App\Support\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('evolution.base_url', 'http://evolution-api:8080');
        config()->set('evolution.api_key', 'master-key-for-tests');
        config()->set('evolution.meta_webhook_url', 'https://wa.example.test/webhook/meta');
        config()->set('evolution.meta_webhook_token', 'verify-token');
    }

    public function test_only_an_administrator_can_list_configurations(): void
    {
        Filial::create(['codigo' => '1', 'descricao' => 'Filial 1']);
        Sanctum::actingAs($this->user('monitoramento'));

        $this->getJson('/whatsapp/configurations')->assertForbidden();

        Sanctum::actingAs($this->user('motorista'));

        $this->getJson('/whatsapp/configurations')->assertForbidden();
    }

    public function test_an_administrator_can_create_a_baileys_configuration_and_receive_qr_code(): void
    {
        $filial = Filial::create(['codigo' => '1', 'descricao' => 'Filial 1']);
        Sanctum::actingAs($this->user('administrador'));
        Http::fake(['*/instance/create' => Http::response([
            'instance' => ['instanceId' => 'remote-id'],
            'hash' => 'instance-key',
            'qrcode' => ['base64' => 'image-data'],
        ], 201)]);

        $this->putJson("/whatsapp/configurations/{$filial->id}/baileys")
            ->assertOk()
            ->assertJsonPath('data.configuration.provider', 'baileys')
            ->assertJsonPath('data.configuration.status', 'waiting_qr')
            ->assertJsonPath('data.qrcode.base64', 'data:image/png;base64,image-data')
            ->assertJsonMissing(['instance_api_key' => 'instance-key'])
        ;
    }

    public function test_official_tokens_are_encrypted_and_never_returned(): void
    {
        $filial = Filial::create(['codigo' => '2', 'descricao' => 'Filial 2']);
        Sanctum::actingAs($this->user('administrador'));
        Http::fake(['*/instance/create' => Http::response(['instance' => ['instanceId' => 'official-id']], 201)]);

        $response = $this->putJson("/whatsapp/configurations/{$filial->id}/official", [
            'access_token' => 'meta-token-with-enough-length-123',
            'phone_number_id' => 'phone-id',
            'business_account_id' => 'business-id',
        ])->assertOk()->assertJsonPath('data.configuration.token_configured', true);

        $raw = DB::table('whatsapp_configurations')->where('filial_id', $filial->id)->value('meta_access_token');
        $this->assertNotSame('meta-token-with-enough-length-123', $raw);
        $this->assertStringNotContainsString('meta-token-with-enough-length-123', $response->getContent());
    }

    public function test_a_remote_failure_does_not_replace_the_current_configuration(): void
    {
        $filial = Filial::create(['codigo' => '3', 'descricao' => 'Filial 3']);
        $administrator = $this->user('administrador');
        Sanctum::actingAs($administrator);

        WhatsAppConfiguration::create([
            'filial_id' => $filial->id,
            'provider' => 'baileys',
            'instance_name' => 'cobeb-existing',
            'instance_api_key' => 'old-key',
            'status' => 'connected',
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Http::fake(['*/instance/create' => Http::response(['message' => 'invalid'], 500)]);

        $this->putJson("/whatsapp/configurations/{$filial->id}/official", [
            'access_token' => 'invalid-meta-token-with-enough-length',
            'phone_number_id' => 'phone-id',
            'business_account_id' => 'business-id',
            'replace' => true,
        ])->assertStatus(502)->assertJsonPath('error_code', 'INVALID_PROVIDER_CREDENTIALS');

        $this->assertDatabaseHas('whatsapp_configurations', [
            'filial_id' => $filial->id,
            'instance_name' => 'cobeb-existing',
            'provider' => 'baileys',
        ]);
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->postJson('/auth/register', [])->assertNotFound();
    }

    public function test_a_global_instance_is_resolved_for_every_branch(): void
    {
        $owner = Filial::create(['codigo' => '10', 'descricao' => 'Matriz']);
        $other = Filial::create(['codigo' => '20', 'descricao' => 'Filial 20']);
        $administrator = $this->user('administrador');
        Sanctum::actingAs($administrator);

        $configuration = WhatsAppConfiguration::create([
            'filial_id' => $owner->id,
            'is_global' => true,
            'global_slot' => 'global',
            'provider' => 'baileys',
            'instance_name' => 'cobeb-global',
            'instance_api_key' => 'global-key',
            'status' => 'connected',
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        $response = $this->getJson('/whatsapp/configurations')->assertOk();
        $entry = collect($response->json('data'))->firstWhere('filial.id', $other->id);

        $this->assertTrue($entry['uses_global']);
        $this->assertSame($configuration->id, $entry['configuration']['id']);
        $this->assertSame($owner->id, WhatsAppConfiguration::resolveForFilial($other->id)?->filial_id);

        Http::fake(['*/message/sendText/cobeb-global' => Http::response(['key' => ['id' => 'message-id']], 201)]);
        app(WhatsAppService::class)->sendMessage($other->id, '37999999999', 'Teste global');

        Http::assertSent(fn($request) => str_ends_with($request->url(), '/message/sendText/cobeb-global'));
    }

    public function test_enabling_another_global_instance_keeps_only_one_active(): void
    {
        $first = Filial::create(['codigo' => '30', 'descricao' => 'Filial 30']);
        $second = Filial::create(['codigo' => '40', 'descricao' => 'Filial 40']);
        $administrator = $this->user('administrador');
        Sanctum::actingAs($administrator);

        foreach ([[$first, true], [$second, false]] as [$filial, $global]) {
            WhatsAppConfiguration::create([
                'filial_id' => $filial->id,
                'is_global' => $global,
                'global_slot' => $global ? 'global' : null,
                'provider' => 'baileys',
                'instance_name' => 'cobeb-' . $filial->codigo,
                'status' => 'connected',
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
        }

        $this->putJson("/whatsapp/configurations/{$second->id}/global", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.is_global', true)
        ;

        $this->assertSame(1, WhatsAppConfiguration::query()->where('is_global', true)->count());
        $this->assertTrue(WhatsAppConfiguration::query()->where('filial_id', $second->id)->firstOrFail()->is_global);
    }

    private function user(string $role): Usuario
    {
        return Usuario::create([
            'id' => (string) Str::uuid(),
            'nome' => 'Usuário de teste',
            'cpf' => (string) random_int(10000000000, 99999999999),
            'senha' => 'Password@123',
            'role' => $role,
            'primeiro_acesso' => false,
        ]);
    }
}
