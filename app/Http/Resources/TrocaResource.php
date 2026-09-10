<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrocaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantidade' => (int) ($this->pivot?->quantidade ?? $this->quantidade),
            'quantidade_movimento' => (int) $this->quantidade,
            'operacao' => $this->operacao,
            'data_operacao' => $this->data_operacao,
            'correcoes' => (int) ($this->correcoes ?? 0),
            'motivo_parcial' => $this->motivo_parcial,
            'responsavel' => UsuarioResource::make($this->whenLoaded('responsavel')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
