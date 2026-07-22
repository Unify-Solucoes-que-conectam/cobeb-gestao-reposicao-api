<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'usuario' => new UsuarioResource($this->whenLoaded('usuario')),
            'menus' => new MenuResource($this->whenLoaded('menus')),
        ];
    }
}
