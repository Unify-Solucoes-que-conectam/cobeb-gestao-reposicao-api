<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GlobalEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('global-notifications');
    }

    public function broadcastAs(): string
    {
        return 'global.notification';
    }

    public function broadcastWith(): array
    {
        return [
            'titulo' => $this->data['titulo'] ?? null,
            'mensagem' => $this->data['mensagem'],
            'tipo' => $this->data['tipo'],
            'data_envio' => now(),
            'lida' => false,
            'link' => $this->data['link'] ?? null,
        ];
    }
}
