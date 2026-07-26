<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
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
            'nome' => $this->nome,
            'cpf' => $this->cpf,
            'role' => $this->role,
            'primeiro_acesso' => (bool) $this->primeiro_acesso,

            'motorista' => MotoristaResource::make($this->whenLoaded('motorista')),
        ];
    }
}
