<?php

namespace App\Http\Controllers;

use App\Http\Resources\TipoAvariaResource;
use App\Models\TipoAvaria;
use Illuminate\Http\Request;

class TiposAvariaController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = TipoAvaria::query();

            if ($request->has('search')) {

                // consultar pelo nome ou código do cliente
                $query->where(function ($q) use ($request) {
                    $q->where('descricao', 'like', '%' . $request->search . '%');
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Tipos de avaria carregados com sucesso.',
                'data' => TipoAvariaResource::collection($query->get())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar tipos de avaria.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
