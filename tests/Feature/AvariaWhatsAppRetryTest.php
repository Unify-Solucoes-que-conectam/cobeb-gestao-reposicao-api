<?php

namespace Tests\Feature;

use App\Jobs\ProcessarRelatorioAvariaJob;
use App\Models\Avaria;
use App\Models\Cliente;
use App\Models\ClienteTelefones;
use App\Models\Filial;
use App\Models\Motorista;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AvariaWhatsAppRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_can_be_retried_while_damage_is_waiting_for_approval(): void
    {
        Queue::fake();

        $filial = Filial::create(['codigo' => '10', 'descricao' => 'Matriz']);
        $usuario = Usuario::create([
            'nome' => 'Motorista',
            'cpf' => '12345678901',
            'senha' => 'secret',
            'role' => 'motorista',
        ]);
        $motorista = Motorista::create([
            'codigo' => 'MOT-1',
            'filial_id' => $filial->id,
            'usuario_id' => $usuario->id,
        ]);
        $cliente = Cliente::create([
            'codigo' => 'CLI-1',
            'nome_fantasia' => 'Cliente Teste',
            'razao_social' => 'Cliente Teste Ltda.',
            'filial_id' => $filial->id,
        ]);
        $telefoneAntigo = ClienteTelefones::create([
            'cliente_id' => $cliente->id,
            'numero' => '3733334444',
            'isWhatsapp' => true,
        ]);
        $avaria = Avaria::create([
            'cliente_id' => $cliente->id,
            'motorista_id' => $motorista->id,
            'status' => 'aguardando_aprovacao',
            'data_emissao' => now()->toDateString(),
            'whatsapp_notification_status' => 'failed',
        ]);

        $response = $this
            ->actingAs($usuario, 'sanctum')
            ->postJson("/api/avarias/{$avaria->id}/whatsapp/retry", [
                'phone' => '(37) 99999-8888',
            ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.whatsapp_notification_status', 'pending')
            ->assertJsonPath('data.whatsapp_notification_phone', '37999998888');

        $this->assertFalse($telefoneAntigo->fresh()->isWhatsapp);
        $this->assertDatabaseHas('cliente_telefones', [
            'cliente_id' => $cliente->id,
            'numero' => '37999998888',
            'isWhatsapp' => true,
        ]);
        Queue::assertPushed(ProcessarRelatorioAvariaJob::class);
    }
}
