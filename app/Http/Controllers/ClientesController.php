<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClienteResource;
use App\Http\Resources\NotaFiscalResource;
use App\Models\Avaria;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClientesController extends Controller
{
    // Listar todos os clientes
    public function index(Request $request)
    {

        // consultar dados dos clientes e filtrar por nome ou cpf se os parâmetros forem fornecidos
        $query = Cliente::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($query) use ($search) {
                $query->where('codigo', 'like', '%' . $search . '%')
                    ->orWhere('nome_fantasia', 'like', '%' . $search . '%');
            });
        }

        $cliente = $query->get()->load('categoria', 'filial', 'contatos');

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de clientes realizada com sucesso.',
                'data' => ClienteResource::collection($cliente)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar clientes.'
            ], 500);
        }
    }

    // listar um cliente específico
    public function show(Request $request, $id)
    {
        // consultar dados do cliente
        $query = Cliente::query()->where('id', $id);

        try {

            $cliente = ClienteResource::collection($query->get()->load('categoria', 'filial', 'contatos'));

            if ($cliente->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente não encontrado.'
                ], 404);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Cliente encontrado com sucesso.',
                    'data' => $cliente->first()
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao encontrar cliente.'
            ], 500);
        }
    }

    public function notasFiscais(Request $request, $id, $notaFiscal_numero = null)
    {
        try {
            // 1. Busca o cliente primeiro
            $cliente = Cliente::find($id);

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente não encontrado.'
                ], 404);
            }

            // 2. Carrega o relacionamento 'notasFiscais' aplicando o filtro
            $cliente->load(['notasFiscais' => function ($query) use ($request, $notaFiscal_numero) {
                if ($request->filled('search')) {
                    $search = $request->input('search');
                    $query->where('numero', 'like', '%' . $search . '%');
                }
                if ($notaFiscal_numero) {
                    $query->where('numero', $notaFiscal_numero);
                }
            }, 'notasFiscais.produtos', 'notasFiscais.produtos.produto']);

            $notasFiscais = $cliente->notasFiscais;

            // Retorna uma coleção chaveada por 'produto_nota_fiscal_id' => total_quantidade_avariada
            $totaisAvariados = Avaria::where('cliente_id', $id)
                ->whereDate('created_at', now()->toDateString())
                ->with('itens')
                ->get()
                ->pluck('itens')
                ->flatten()
                ->groupBy('produto_nota_fiscal_id')
                ->map(fn($itens) => $itens->sum('quantidade_avariada'));

            // Abate o total avariado da quantidade disponível do produto na nota
            if ($totaisAvariados->isNotEmpty()) {
                foreach ($notasFiscais as $notaFiscal) {
                    foreach ($notaFiscal->produtos as $produtoNota) {
                        // $produtoNota->id é a PK da tabela 'produto_nota_fiscal'
                        if ($totaisAvariados->has($produtoNota->id)) {
                            $qtdAvariada = $totaisAvariados->get($produtoNota->id);

                            // Subtrai a quantidade avariada e garante que não fique negativa
                            $produtoNota->quantidade = max(0, $produtoNota->quantidade - $qtdAvariada);
                            // adiciona uma propriedade para indicar a quantidade avariada
                            $produtoNota['quantidade_avariada'] = $qtdAvariada;
                        }
                    }
                }
            }

            // Retorna a coleção filtrada
            return response()->json([
                'success' => true,
                'message' => 'Notas fiscais vinculadas encontradas com sucesso.',
                'data'    => $notasFiscais->count() > 1
                    ? NotaFiscalResource::collection($notasFiscais)
                    : ($notasFiscais->isNotEmpty() ? new NotaFiscalResource($notasFiscais->first()) : null)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao encontrar notas fiscais vinculadas.',
                'error'   => $e->getMessage() // Adicionado para facilitar o debug
            ], 500);
        }
    }
}
