<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapaResource extends JsonResource
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
            'codigo' => $this->codigo,
            'filial' => new FilialResource($this->whenLoaded('filial')),
            'motorista' => new MotoristaResource($this->whenLoaded('motorista')),
            'data_entrega' => $this->data_entrega,
            'placa' => $this->placa,
            'clientes' => ClientesMapaResource::collection($this->whenLoaded('clientes')),
        ];
    }
}
