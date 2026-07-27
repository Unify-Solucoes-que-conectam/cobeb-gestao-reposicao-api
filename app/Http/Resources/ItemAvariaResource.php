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
            'produto' => $this->whenLoaded('produtoNotaFiscal', fn() => [
                'id' => $this->produtoNotaFiscal->produto->id,
                'quantidade_avariada' => $this->quantidade_avariada,
                'quantidade_avariada' => $this->quantidade_avariada,
                'codigo' => $this->produtoNotaFiscal->produto->codigo,
                'descricao' => $this->produtoNotaFiscal->produto->descricao,
                'tipo_avaria' => new TipoAvariaResource($this->whenLoaded('tipoAvaria')),
            ]),
            'quantidade_avariada' => $this->quantidade_avariada,
        ];
    }
}
