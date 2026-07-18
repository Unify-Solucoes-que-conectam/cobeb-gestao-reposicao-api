<?php

namespace App\Notifications;

use App\Contracts\DatabaseNotifiable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserNotification extends Notification implements DatabaseNotifiable, ShouldQueue
{
    use Queueable;

    public $data; // Mudado de protected para public para serialização

    /**
     * Create a new notification instance.
     */
    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast()
    {
        // Defina o id da notificação com seu valor customizado
        $this->id = $this->data['id'];

        return new BroadcastMessage([
            'id' => $this->data['id'],
            'titulo' => $this->data['titulo'] ?? null,
            'mensagem' => $this->data['mensagem'],
            'tipo' => $this->data['tipo'],
            'data_envio' => now(),
            'lida' => false,
            'link' => $this->data['link'] ?? null,
        ]);
    }

    public function broadcastOn()
    {
        return [new PrivateChannel('notifications.' . $this->data['usuario_id'])];
    }

    /**
     * Opcional: Define o nome do evento para o frontend ouvir.
     * Padrão: Illuminate\Notifications\Events\BroadcastNotificationCreated
     */
    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
