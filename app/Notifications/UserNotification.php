<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Broadcasting\PrivateChannel;

class UserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $data;

    /**
     * Cria uma nova instância da notificação.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Define por onde a notificação será entregue (Banco + WebSocket).
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Dados salvos na tabela de notificações do banco.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'titulo'     => $this->data['titulo'] ?? null,
            'mensagem'   => $this->data['mensagem'],
            'tipo'       => $this->data['tipo'] ?? 'info',
            'link'       => $this->data['link'] ?? null,
            'menu_id'    => $this->data['menu_id'] ?? null,
            'data_envio' => now(),
        ];
    }

    /**
     * Dados transmitidos via WebSocket/Reverb para o app.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->data['id'] ?? null, // ID da notificação gerado pelo Laravel
            'titulo'     => $this->data['titulo'] ?? null,
            'mensagem'   => $this->data['mensagem'],
            'tipo'       => $this->data['tipo'] ?? 'info',
            'link'       => $this->data['link'] ?? null,
            'menu_id'    => $this->data['menu_id'] ?? null,
            'data_envio' => now()->toIso8601String(),
        ]);
    }

    /**
     * Define o canal PRIVADO do usuário que receberá a mensagem.
     */
    public function broadcastOn(): PrivateChannel
    {
        // Pega o ID do usuário diretamente do destinatário ($notifiable) ou do $data
        $userId = $this->data['usuario_id'] ?? null;

        return new PrivateChannel('notifications.' . $userId);
    }

    /**
     * Nome do evento escutado pelo Frontend/Mobile.
     */
    public function broadcastAs(): string
    {
        return 'user.notification';
    }
}
