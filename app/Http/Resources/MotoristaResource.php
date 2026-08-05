<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotoristaResource extends JsonResource
{
    protected $dataEntrega;

    // Construtor para receber a data da avaria (opcional)
    public function __construct($resource, $dataEntrega = null)
    {
        parent::__construct($resource);
        $this->dataEntrega = $dataEntrega;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $esconderCampos = $request->routeIs(['auth.login']);

        $mapa = ($this->relationLoaded('mapas') && !is_null($this->dataEntrega))
            ? $this->mapas->firstWhere('data_entrega', $this->dataEntrega)
            : null;

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nome' => $this->when(!$esconderCampos, $this->usuario?->nome),
            'cpf'  => $this->when(!$esconderCampos, $this->usuario?->cpf),
            'status' => $this->status,
            'data_admissao' => $this->data_admissao,
            'data_inativacao' => $this->data_inativacao,
            'mapa' => $this->relationLoaded('mapaAtual') && $this->mapaAtual
                ? new MapaResource($this->mapaAtual)
                : ($mapa ? new MapaResource($mapa) : null),
            'filial' => new FilialResource($this->whenLoaded('filial')),
            'cluster' => new ClusterResource($this->whenLoaded('cluster')),
        ];
    }
}
