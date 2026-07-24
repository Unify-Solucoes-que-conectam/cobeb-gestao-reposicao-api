<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'motorista' => new MotoristaResource($this->whenLoaded('motorista')),
            'cliente' => new ClienteResource($this->whenLoaded('cliente')),
            'status' => $this->status,
            'data_emissao' => $this->data_emissao,
            'aprovador' => new UsuarioResource($this->whenLoaded('aprovador')),
            'data_aprovacao' => $this->data_aprovacao,
            'motivo_reprovacao' => $this->motivo_reprovacao,
            'nota_fiscal' => NotaFiscalResource::make($this->nota_fiscal),
            'itens' => ItemAvariaResource::collection($this->whenLoaded('itens')),
            'anexos' => $this->whenLoaded('anexos') ? AnexosAvariaResource::collection($this->anexos) : [],
        ];
    }
}
