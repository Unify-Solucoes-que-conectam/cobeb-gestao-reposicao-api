<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'motorista' => new MotoristaResource(
                $this->whenLoaded('motorista'),
                $this->data_emissao
            ),
            'cliente' => new ClienteResource($this->whenLoaded('cliente')),
            'status' => $this->status,
            'data_emissao' => $this->data_emissao,
            'aprovador' => new UsuarioResource($this->whenLoaded('aprovador')),
            'data_aprovacao' => $this->data_aprovacao,
            'motivo_reprovacao' => $this->motivo_reprovacao,
            'whatsapp_notification_status' => $this->whatsapp_notification_status,
            'whatsapp_notification_phone' => $this->whatsapp_notification_phone,
            'whatsapp_notification_error' => $this->whatsapp_notification_error,
            'whatsapp_notification_sent_at' => $this->whatsapp_notification_sent_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'nota_fiscal' => NotaFiscalResource::make($this->nota_fiscal),
            'itens' => ItemAvariaResource::collection($this->whenLoaded('itens')),
            'anexos' => $this->whenLoaded('anexos') ? AnexosAvariaResource::collection($this->anexos) : [],
        ];
    }
}
