<?php

namespace App\Http\Resources;
use App\Http\Resources\UsuarioResource;
use App\Http\Resources\FilialResource;
use App\Http\Resources\ClusterResource;
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

        $usuario = UsuarioResource::make($this->whenLoaded('usuario'));

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,

            'nome' => $this->usuario->nome,
            'cpf' => $this->usuario->cpf,

            'status' => $this->status,
            'data_admissao' => $this->data_admissao,
            'data_inativacao' => $this->data_inativacao,

            // Aqui carregamos os objetos, mas note que NÃO incluímos filial_id e cluster_id
            'filial' => new FilialResource($this->whenLoaded('filial')),
            'cluster' => new ClusterResource($this->whenLoaded('cluster')),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
