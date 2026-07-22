<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotoristaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $esconderCampos = $request->routeIs(['auth.login']);

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nome' => $this->when(!$esconderCampos, $this->usuario?->nome),
            'cpf'  => $this->when(!$esconderCampos, $this->usuario?->cpf),
            'status' => $this->status,
            'data_admissao' => $this->data_admissao,
            'data_inativacao' => $this->data_inativacao,

            // Aqui carregamos os objetos, mas note que NÃO incluímos filial_id e cluster_id
            'mapa' => new MapaResource($this->whenLoaded('mapaAtual')),
            'filial' => new FilialResource($this->whenLoaded('filial')),
            'cluster' => new ClusterResource($this->whenLoaded('cluster')),
        ];
    }
}
