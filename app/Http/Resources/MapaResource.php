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
            'clientes' => $this->whenLoaded('clientes', function () {
                // Se a relação aninhada 'cliente' estiver carregada (ex: tabela pivot)
                if ($this->relationLoaded('clientes') && $this->clientes->first()?->relationLoaded('cliente')) {
                    return ClienteResource::collection($this->clientes->pluck('cliente'));
                }

                // Retorno padrão caso seja uma relação direta
                return ClienteResource::collection($this->clientes);
            }),
            'motorista' => new MotoristaResource($this->whenLoaded('motorista')),
            'filial' => new FilialResource($this->whenLoaded('filial')),
            'data_entrega' => $this->data_entrega,
            'placa' => $this->placa,
        ];
    }
}
