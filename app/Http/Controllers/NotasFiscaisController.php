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
        $relations = [
            'produtos',
            'produtos.produto',
            'produtos.produto.tipoMarca',
            'produtos.produto.embalagem',
        ];

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'like', '%' . $search . '%');
            });
        }

        // consulta notas de um cliente específico se o parâmetro 'cliente_id' for fornecido na requisição
        if ($request->filled('cliente_id')) {
            $clienteId = $request->input('cliente_id');
            $query->where('cliente_id', $clienteId);

            $relations[] = 'cliente';
        }

        $notasFiscais = $query->get()->load($relations);

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de notas fiscais realizada com sucesso.',
                'data' => NotaFiscalResource::collection($notasFiscais)
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

        try {

            $notaFiscal = $query->with([
                'cliente',
                'produtos',
                'produtos.produto',
                'produtos.produto.tipoMarca',
                'produtos.produto.embalagem',
            ])->first();

            if (!$notaFiscal || !$notaFiscal->resource) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nota fiscal não encontrada.',
                    'data' => NotaFiscalResource::make($notaFiscal)
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
