<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
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
            'titulo' => $this->titulo,
            'icone' => $this->icone,
            'rota' => $this->rota,
            'ordem' => $this->ordem,
            'menu_pai_id' => $this->menu_pai_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sub_menus' => MenuResource::collection($this->whenLoaded('subMenus')),
        ];
    }
}
