<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagemWhatsAppJob;
use App\Jobs\PrepareAvariaWhatsAppNotificationJob;
use App\Models\Avaria;
use App\Models\AvariaWhatsAppNotification;
use App\Models\Cliente;
use App\Models\ClienteTelefones;
use App\Models\Filial;
use App\Models\Motorista;
use App\Models\Usuario;
use App\Models\WhatsAppConfiguration;
use App\Support\WhatsAppRecipientResolver;
use App\Support\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvariaWhatsAppContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('evolution.base_url', 'http://evolution-api:8080');
        config()->set('evolution.api_key', 'test-master-key');
    }

    public function test_monitoring_cannot_save_a_number_that_does_not_exist_on_whatsapp(): void
    {
        [$user, $avaria] = $this->scenario('monitoramento');
        Sanctum::actingAs($user);
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            ['number' => '553732590820', 'exists' => false],
        ])]);

        $response = $this->postJson("/avarias/{$avaria->id}/whatsapp-contact", [
            'phone' => '(37) 3259-0820',
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'WHATSAPP_NUMBER_NOT_FOUND');
        $this->assertDatabaseMissing('cliente_telefones', [
            'cliente_id' => $avaria->cliente_id,
            'numero' => '3732590820',
            'isWhatsapp' => true,
        ]);
    }

    public function test_a_valid_number_becomes_the_default_and_requeues_the_notification(): void
    {
        Queue::fake();
        [$user, $avaria] = $this->scenario('administrador');
        Sanctum::actingAs($user);
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            ['number' => '5537998247669', 'exists' => true],
        ])]);

        $response = $this->postJson("/avarias/{$avaria->id}/whatsapp-contact", [
            'phone' => '+55 (37) 99824-7669',
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseHas('cliente_telefones', [
            'cliente_id' => $avaria->cliente_id,
            'numero' => '37998247669',
            'isWhatsapp' => true,
            'whatsapp_validation_status' => 'valid',
        ]);
        Queue::assertPushed(PrepareAvariaWhatsAppNotificationJob::class);
    }

    public function test_driver_cannot_change_the_customer_contact(): void
    {
        [$user, $avaria] = $this->scenario('motorista');
        Sanctum::actingAs($user);

        $this->postJson("/avarias/{$avaria->id}/whatsapp-contact", [
            'phone' => '37998247669',
        ])->assertForbidden();
    }

    public function test_it_does_not_change_the_contact_when_there_is_no_pending_notification(): void
    {
        [$user, $avaria] = $this->scenario('administrador');
        Sanctum::actingAs($user);
        $avaria->whatsappNotifications()->update([
            'status' => AvariaWhatsAppNotification::STATUS_ACCEPTED,
        ]);
        Http::preventStrayRequests();

        $this->postJson("/avarias/{$avaria->id}/whatsapp-contact", [
            'phone' => '37998247669',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'WHATSAPP_NOTIFICATION_NOT_PENDING')
        ;

        $this->assertDatabaseMissing('cliente_telefones', [
            'cliente_id' => $avaria->cliente_id,
            'numero' => '37998247669',
        ]);
    }

    public function test_a_second_retry_request_is_rejected_while_the_notification_is_queued(): void
    {
        [$user, $avaria] = $this->scenario('monitoramento');
        Sanctum::actingAs($user);
        $avaria->whatsappNotifications()->update([
            'status' => AvariaWhatsAppNotification::STATUS_QUEUED,
        ]);
        Http::preventStrayRequests();

        $this->postJson("/avarias/{$avaria->id}/whatsapp-contact", [
            'phone' => '37998247669',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'WHATSAPP_ALREADY_QUEUED')
        ;
    }

    public function test_it_uses_a_valid_alternative_when_the_default_contact_is_not_on_whatsapp(): void
    {
        [, $avaria] = $this->scenario('monitoramento');
        ClienteTelefones::create([
            'cliente_id' => $avaria->cliente_id,
            'numero' => '37998247669',
            'isWhatsapp' => false,
        ]);
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            ['number' => '5537911112222', 'exists' => false],
            ['number' => '5537998247669', 'exists' => true],
        ])]);

        $resolved = app(WhatsAppRecipientResolver::class)->resolve(
            $avaria->cliente,
            $avaria->motorista->filial_id,
        );

        $this->assertSame('37998247669', $resolved['phone']);
        $this->assertDatabaseHas('cliente_telefones', [
            'cliente_id' => $avaria->cliente_id,
            'numero' => '37998247669',
            'isWhatsapp' => true,
            'whatsapp_validation_status' => 'valid',
        ]);
        $this->assertDatabaseHas('cliente_telefones', [
            'cliente_id' => $avaria->cliente_id,
            'numero' => '37911112222',
            'isWhatsapp' => false,
            'whatsapp_validation_status' => 'invalid',
        ]);
    }

    public function test_the_send_job_persists_evolution_acceptance(): void
    {
        [, $avaria] = $this->scenario('monitoramento');
        $notification = $avaria->whatsappNotifications()->firstOrFail();
        Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'message-id']], 201)]);
        $job = new EnviarMensagemWhatsAppJob(
            $avaria->motorista->filial_id,
            '37998247669',
            'text',
            'Mensagem de teste',
            null,
            null,
            'avaria_approved',
            [],
            [$notification->id],
        );

        $job->handle(app(WhatsAppService::class));

        $this->assertDatabaseHas('avaria_whatsapp_notifications', [
            'id' => $notification->id,
            'status' => AvariaWhatsAppNotification::STATUS_ACCEPTED,
            'phone' => '37998247669',
            'attempts' => 1,
        ]);
    }

    private function scenario(string $role): array
    {
        $filial = Filial::create(['codigo' => uniqid(), 'descricao' => 'Matriz']);
        $user = Usuario::create([
            'nome' => 'Usuário de teste',
            'cpf' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'senha' => 'Senha@123',
            'role' => $role,
        ]);
        $driverUser = $role === 'motorista' ? $user : Usuario::create([
            'nome' => 'Motorista de teste',
            'cpf' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'senha' => 'Senha@123',
            'role' => 'motorista',
        ]);
        $motorista = Motorista::create([
            'codigo' => uniqid('M'),
            'filial_id' => $filial->id,
            'usuario_id' => $driverUser->id,
            'status' => 'ativo',
        ]);
        $cliente = Cliente::create([
            'codigo' => uniqid('C'),
            'nome_fantasia' => 'Cliente teste',
            'razao_social' => 'Cliente teste',
            'filial_id' => $filial->id,
            'status' => 'ativo',
        ]);
        ClienteTelefones::create([
            'cliente_id' => $cliente->id,
            'numero' => '37911112222',
            'isWhatsapp' => true,
            'whatsapp_validation_status' => 'invalid',
        ]);
        $avaria = Avaria::create([
            'cliente_id' => $cliente->id,
            'motorista_id' => $motorista->id,
            'status' => 'aguardando_aprovacao',
            'data_emissao' => now()->toDateString(),
        ]);
        AvariaWhatsAppNotification::create([
            'avaria_id' => $avaria->id,
            'correlation_id' => fake()->uuid(),
            'event' => AvariaWhatsAppNotification::EVENT_REPORT,
            'status' => AvariaWhatsAppNotification::STATUS_REQUIRES_PHONE,
        ]);
        WhatsAppConfiguration::create([
            'filial_id' => $filial->id,
            'provider' => 'baileys',
            'instance_name' => 'test-' . $filial->codigo,
            'status' => 'connected',
        ]);

        return [$user, $avaria];
    }
}
