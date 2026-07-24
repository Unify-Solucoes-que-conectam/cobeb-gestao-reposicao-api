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
                'codigo' => $this->produtoNotaFiscal->produto->codigo,
                'descricao' => $this->produtoNotaFiscal->produto->descricao,
                'quantidade_total' => $this->produtoNotaFiscal->quantidade,
            ]),
            'quantidade_avariada' => $this->quantidade_avariada,
            'tipo_avaria' => new TipoAvariaResource($this->whenLoaded('tipoAvaria')),
        ];
    }
}
