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
            'codigo' => $this->codigo,
            'descricao' => $this->descricao,
            'marca' => new TipoMarcaResource($this->whenLoaded('tipoMarca')),
            'embalagem' => new EmbalagemResource($this->whenLoaded('embalagem')),
            'ean' => $this->ean,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
