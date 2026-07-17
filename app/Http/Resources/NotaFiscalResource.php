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

            // Aqui carregamos os objetos
            'numero' => $this->numero,
            'pedido' => $this->pedido,
            'data_operacao' => $this->data_operacao,
            'data_emissao' => $this->data_emissao,
            'valor_bruto' => $this->valor_bruto,
            'total_desconto' => $this->total_desconto,
            'valor_total' => $this->valor_total,
            'status' => $this->status,

            'usuario_responsavel_id' => $this->usuario_responsavel_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'produtos' => $this->whenLoaded('produtos'),
            'cliente' => $this->whenLoaded('cliente'),
        ];
    }
}
