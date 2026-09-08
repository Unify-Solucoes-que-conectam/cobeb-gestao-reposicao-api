<?php

namespace App\Http\Resources;

use App\Models\AvariaWhatsAppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $event = match ($this->status) {
            'aguardando_aprovacao' => AvariaWhatsAppNotification::EVENT_REPORT,
            'aprovada' => AvariaWhatsAppNotification::EVENT_APPROVED,
            'reprovada' => AvariaWhatsAppNotification::EVENT_REJECTED,
            default => null,
        };
        $notification = $event && $this->relationLoaded('whatsappNotifications')
            ? $this->whatsappNotifications->firstWhere('event', $event)
            : null;
        $defaultPhone = $this->relationLoaded('cliente') && $this->cliente?->relationLoaded('contatos')
            ? $this->cliente->contatos->firstWhere('isWhatsapp', true)?->numero
            : null;

        return [
            'id' => $this->id,
            'motorista' => new MotoristaResource(
                $this->whenLoaded('motorista'),
                $this->data_emissao,
            ),
            'cliente' => new ClienteResource($this->whenLoaded('cliente')),
            'status' => $this->status,
            'data_emissao' => $this->data_emissao,
            'aprovador' => new UsuarioResource($this->whenLoaded('aprovador')),
            'data_aprovacao' => $this->data_aprovacao,
            'motivo_reprovacao' => $this->motivo_reprovacao,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'nota_fiscal' => NotaFiscalResource::make($this->nota_fiscal),
            'itens' => ItemAvariaResource::collection($this->whenLoaded('itens')),
            'anexos' => $this->whenLoaded('anexos') ? AnexosAvariaResource::collection($this->anexos) : [],
            'whatsapp_notification' => $notification ? [
                'event' => $notification->event,
                'status' => $notification->status,
                'phone' => $notification->phone,
                'error_code' => $notification->error_code,
                'error_message' => $notification->error_message,
                'last_attempt_at' => $notification->last_attempt_at?->toIso8601String(),
                'accepted_at' => $notification->accepted_at?->toIso8601String(),
            ] : ($event ? [
                'event' => $event,
                'status' => 'unknown',
                'phone' => $defaultPhone,
                'error_code' => null,
                'error_message' => null,
                'last_attempt_at' => null,
                'accepted_at' => null,
            ] : null),
        ];
    }
}
