<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
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

            // Aqui carregamos os objetos
            'descricao' => $this->descricao,
            'codigo' => $this->codigo,
            'valor_unitario' => $this->valor_unitario,
            'ean' => $this->ean,

            'usuario_responsavel_id' => $this->usuario_responsavel_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'tipo_marca' => $this->whenLoaded('tipoMarca'),
            'embalagem' => $this->whenLoaded('embalagem'),
        ];
    }
}
