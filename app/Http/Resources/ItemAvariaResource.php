<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemAvariaResource extends JsonResource
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
            'produto' => new ProdutosNotaFiscalResource($this->whenLoaded('produto')),
            'quantidade_avariada' => $this->quantidade_avariada,
            'tipo_avaria' => new TipoAvariaResource($this->whenLoaded('tipoAvaria')),
        ];
    }
}
