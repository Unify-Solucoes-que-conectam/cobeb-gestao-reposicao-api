<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrocaResource;
use App\Models\Troca;
use Exception;
use Illuminate\Http\Request;

class TrocasController extends Controller
{
    public function index(Request $request)
    {

        try {
            $trocas = Troca::query()->get();

            return response()->json([
                'message' => 'Trocas consultadas com sucesso',
                'success' => true,
                'data' => TrocaResource::collection($trocas)
            ], 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Erro ao consultar trocas',
                'success' => false
            ], 500);
        }
    }
}
