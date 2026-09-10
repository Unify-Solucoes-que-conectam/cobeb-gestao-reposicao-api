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

        $mapa = null;

        if ($this->relationLoaded('mapas') && $this->mapas->isNotEmpty()) {
            $dataEntrega = $this->dataEntrega
                ? substr((string) $this->dataEntrega, 0, 10)
                : null;
            $mapasOrdenados = $this->mapas->sortByDesc(
                fn ($mapa) => substr((string) $mapa->data_entrega, 0, 10) . '|' . $mapa->created_at
            );

            if ($dataEntrega) {
                $mapa = $mapasOrdenados->first(
                    fn ($mapa) => substr((string) $mapa->data_entrega, 0, 10) === $dataEntrega
                );

                // Registros antigos podem não possuir um mapa exatamente no dia da avaria.
                // Nesse caso, usa o último mapa conhecido até aquela data.
                $mapa ??= $mapasOrdenados->first(
                    fn ($mapa) => substr((string) $mapa->data_entrega, 0, 10) <= $dataEntrega
                );
            }

            // Se não houver mapa anterior, ainda retorna o vínculo mais recente do motorista.
            $mapa ??= $mapasOrdenados->first();
        }

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
