<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProdutosNotaFiscalResource;

class NotaFiscalAvariaResource extends JsonResource
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
            $this->mergeWhen($this->relationLoaded('nota_fiscal'), function () {
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

                    // Condiciona a exibição dos produtos ao fato deles estarem carregados
                    'produtos' => $this->nota_fiscal->relationLoaded('produtos')
                        ? ProdutosNotaFiscalResource::collection($this->nota_fiscal->produtos)
                        : [],

                    'created_at' => $this->nota_fiscal->created_at,
                    'updated_at' => $this->nota_fiscal->updated_at,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
