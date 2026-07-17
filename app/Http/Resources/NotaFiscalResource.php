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

            // carrega os produtos e formata os dados
            'produtos' => $this->whenLoaded('produtos', function () {
                return $this->produtos->map(function ($item) {
                    // Se 'produtos' for uma relação belongsToMany, os dados específicos da nota ficam em ->pivot
                    // Se for um Model intermediário (HasMany), os dados ficam direto no $item

                    // Buscando o produto final carregado via 'produtos.produto'
                    $produtoOriginal = $item->produto;

                    // calcula valor total com base na quantidade e valor unitário do produto original
                    $item->valor_total = $produtoOriginal ? $produtoOriginal->valor_unitario * $item->quantidade : null;

                    return [
                        'id' => $item->produto_id,
                        'codigo' => $produtoOriginal ? $produtoOriginal->codigo : null,
                        'descricao' => $produtoOriginal ? $produtoOriginal->descricao : null,
                        'quantidade' => $item->quantidade,
                        'valor_unitario' => $produtoOriginal->valor_unitario,
                        'valor_total' => $item->valor_total,
                    ];
                });
            }),
            'cliente' => $this->whenLoaded('cliente'),
        ];
    }
}
