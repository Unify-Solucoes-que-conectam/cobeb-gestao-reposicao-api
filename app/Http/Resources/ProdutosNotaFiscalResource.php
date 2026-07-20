<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutosNotaFiscalResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        if (!$this->relationLoaded('produto')) {
            return [
                'id' => $this->id,
                'quantidade' => $this->quantidade,
            ];
        }

        $produtoData = (new ProdutoResource($this->produto))->resolve($request);

        // Mescla a quantidade (e o ID da tabela produtos_nota_fiscal) dentro do array do produto
        return array_merge($produtoData, [
            'quantidade' => $this->quantidade,
        ]);
    }
}
