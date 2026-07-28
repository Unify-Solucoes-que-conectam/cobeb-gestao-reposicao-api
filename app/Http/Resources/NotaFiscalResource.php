<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaFiscalResource extends JsonResource
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
            'numero' => $this->numero,
            'pedido' => $this->pedido,
            'data_emissao' => $this->data_emissao,
            'produtos' => ProdutosNotaFiscalResource::collection($this->whenLoaded('produtos')),
        ];
    }
}
