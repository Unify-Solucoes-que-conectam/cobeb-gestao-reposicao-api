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
            'anexos' => AnexosAvariaResource::collection($this->whenLoaded('anexos')),
            'cliente' => new ClienteResource($this->whenLoaded('cliente')),
            'notas_fiscais' => NotaFiscalAvariaResource::collection($this->whenLoaded('notasFiscais')),
            'mapa' => new MapaResource($this->whenLoaded('mapa')),
            'status' => $this->status,
            'usuario_responsavel_id' => $this->usuario_responsavel_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
