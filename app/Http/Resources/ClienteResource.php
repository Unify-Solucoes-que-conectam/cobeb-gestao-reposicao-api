<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
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

            // aqui carregamos os relacionamentos
            'filial' => FilialResource::make($this->whenLoaded('filial')),
            'categoria' => CategoriaResource::make($this->whenLoaded('categoria')),
            'contatos' => ClienteTelefoneResource::collection($this->whenLoaded('contatos')),

            // Aqui carregamos os objetos
            'codigo' => $this->codigo,
            'documento' => $this->documento,
            'nome_fantasia' => $this->nome_fantasia,
            'razao_social' => $this->razao_social,
            'endereco' => $this->endereco,
            'complemento' => $this->complemento,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'cep' => $this->cep,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'tipo_pessoa' => $this->tipo_pessoa,
            'quantidade_notas' => $this->whenCounted('notasFiscais'),
        ];
    }
}
