<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
use Illuminate\Http\Request;

class NotasFiscaisController extends Controller
{
    // Listar todos as notas fiscais
    public function index(Request $request)
    {

        // consultar dados das notas fiscais e filtrar por número fornecido no parâmetro 'search' da requisição
        $query = NotaFiscal::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'like', '%' . $search . '%');
            });
        }

        // consultar quais detalhes devem ser carregados com base no parâmetro 'detalhar' (boolean) da requisição
        if ($request->boolean('detalhar')) {
            $query->with([
                'produtos',
                'cliente',
            ]);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de notas fiscais realizada com sucesso.',
                'data' => NotaFiscalResource::collection($query->get())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar notas fiscais.'
            ], 500);
        }
    }

    // listar uma nota fiscal específica
    public function show(Request $request, $numero)
    {
        // consultar dados da nota fiscal
        $query = NotaFiscal::query()->where('numero', $numero);

        // consultar quais detalhes devem ser carregados com base no parâmetro 'detalhar' (boolean) da requisição
        if ($request->filled('detalhar') && $request->boolean('detalhar') === true) {
            $query->with([
                'produtos',
                'cliente',
            ]);
        }

        try {

            $notaFiscal = NotaFiscalResource::collection($query->get())->first();

            if (!$notaFiscal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nota fiscal não encontrada.',
                    'data' => []
                ], 404);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Consulta de nota fiscal realizada com sucesso.',
                    'data' => [$notaFiscal]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar nota fiscal.'
            ], 500);
        }
    }
}
