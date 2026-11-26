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
            'grupo' => $this->whenLoaded('grupo', fn () => $this->grupo ? [
                'id' => $this->grupo->id,
                'nome' => $this->grupo->nome,
                'perfil' => $this->grupo->perfil,
            ] : null),
            'acesso_geral' => (bool) $this->acesso_geral,
            'filiais' => $this->whenLoaded('filiais', fn () => $this->filiais->map(fn ($f) => [
                'id' => $f->id, 'codigo' => $f->codigo, 'descricao' => $f->descricao,
            ])),
            'primeiro_acesso' => (bool) $this->primeiro_acesso,

            'motorista' => MotoristaResource::make($this->whenLoaded('motorista')),
        ];
    }
}
