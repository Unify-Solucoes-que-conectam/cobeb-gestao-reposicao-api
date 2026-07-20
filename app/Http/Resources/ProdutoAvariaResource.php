<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoAvariaResource extends JsonResource
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

            $this->mergeWhen($this->relationLoaded('produto'), [
                'id' => $this->produto->id,
                'codigo' => $this->produto->codigo,
                'descricao' => $this->produto->descricao,
                'tipo_marca' => $this->produto->tipo_marca,
                'embalagem' => $this->produto->embalagem,
                'valor_unitario' => $this->produto->valor_unitario,
            ]),

            // Aqui carregamos os objetos
            'tipo_avaria' => new TipoAvariaResource($this->whenLoaded('tipoAvaria')),
            'quantidade' => $this->quantidade,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
