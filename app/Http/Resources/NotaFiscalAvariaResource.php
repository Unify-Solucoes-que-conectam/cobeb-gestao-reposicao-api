<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProdutosNotaFiscalResource;

class NotaFiscalAvariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            $this->mergeWhen($this->relationLoaded('nota_fiscal'), function () {

                // 1. Pega os IDs dos produtos que foram marcados como avariados nessa Avaria
                $produtosAvariadosIds = [];
                if ($this->relationLoaded('avaria') && $this->avaria->relationLoaded('produtos')) {
                    $produtosAvariadosIds = $this->avaria->produtos->pluck('produto_id')->toArray();
                }

                // 2. Filtra os produtos da Nota Fiscal para manter APENAS os avariados
                $produtosFiltrados = $this->nota_fiscal->relationLoaded('produtos')
                    ? $this->nota_fiscal->produtos->filter(function ($itemNota) use ($produtosAvariadosIds) {
                        return in_array($itemNota->produto_id, $produtosAvariadosIds);
                    })
                    : collect([]);

                return [
                    'id' => $this->nota_fiscal->id,
                    'numero' => $this->nota_fiscal->numero,
                    'pedido' => $this->nota_fiscal->pedido,
                    'data_operacao' => $this->nota_fiscal->data_operacao,
                    'data_emissao' => $this->nota_fiscal->data_emissao,
                    'valor_bruto' => $this->nota_fiscal->valor_bruto,
                    'total_desconto' => $this->nota_fiscal->total_desconto,
                    'valor_total' => $this->nota_fiscal->valor_total,
                    'status' => $this->nota_fiscal->status,

                    // 3. Retorna a coleção filtrada
                    'produtos' => ProdutosNotaFiscalResource::collection($produtosFiltrados),

                    'created_at' => $this->nota_fiscal->created_at,
                    'updated_at' => $this->nota_fiscal->updated_at,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
