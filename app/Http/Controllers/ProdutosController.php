<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use Illuminate\Http\Request;

class ProdutosController extends Controller
{
    // Listar todos os produtos
    public function index(Request $request)
    {

        // consultar dados ddos produtos e filtrar por código fornecido no parâmetro 'search' da requisição
        $query = Produto::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', '%' . $search . '%');
            });
        }

        $produto = $query->get()->load([
            'tipoMarca',
            'embalagem',
        ]);

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de produtos realizada com sucesso.',
                'data' => ProdutoResource::collection($produto)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar produtos.'
            ], 500);
        }
    }

    // listar um produto específico
    public function show(Request $request, $codigo)
    {
        // consultar dados do produto
        $query = Produto::query()->where('codigo', $codigo);

        try {

            $produto = ProdutoResource::collection($query->get()->load([
                'tipoMarca',
                'embalagem',
            ]))->first();

            if (!$produto) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produto não encontrado.',
                    'data' => []
                ], 404);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Produto encontrado com sucesso.',
                    'data' => [$produto]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro encontrar o produto.'
            ], 500);
        }
    }
}
