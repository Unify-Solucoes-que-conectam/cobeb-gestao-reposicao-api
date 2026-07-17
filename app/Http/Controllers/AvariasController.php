<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAvariaRequest;
use App\Http\Resources\AvariaResource;
use App\Models\AnexosAvaria;
use App\Models\Avaria;
use App\Models\NotasFiscaisAvaria;
use App\Models\ProdutosAvaria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AvariasController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Avaria::query();

            if ($request->has('search')) {

                // consultar pelo código do mapa
                $query->whereHas('mapa', function ($q) use ($request) {
                    $q->where('codigo', 'like', '%' . $request->search . '%');
                });

                // consultar pelo nome ou código do cliente
                $query->orWhereHas('cliente', function ($q) use ($request) {
                    $q->where('nome_fantasia', 'like', '%' . $request->search . '%')->orWhere('codigo', 'like', '%' . $request->search . '%');
                });
            }

            $avarias = $query->with([
                'cliente',
                'mapa',
                'mapa.motorista.filial',
                'mapa.motorista.cluster',
                'notasFiscais.nota_fiscal',
                'produtos.produto',
                'produtos.tipoAvaria',
                'produtos.produto.tipoMarca',
                'anexos'
            ])->get();

            return response()->json([
                'success' => true,
                'message' => 'Avarias carregadas com sucesso.',
                'data' => AvariaResource::collection($avarias)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar avarias.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreAvariaRequest $request)
    {
        try {
            // Inicia a transação
            $avaria = DB::transaction(function () use ($request) {

                // Pegando o ID do usuário autenticado para preencher o 'usuario_responsavel_id'
                $usuarioId = $request->user()->id;

                // 1. Cria a Avaria principal
                $avaria = Avaria::create([
                    'cliente_id' => $request->cliente_id,
                    'mapa_id' => $request->mapa_id,
                    'usuario_responsavel_id' => $usuarioId,
                ]);

                // 2. Associa as Notas Fiscais (se existirem)
                if ($request->has('notas_fiscais')) {
                    foreach ($request->notas_fiscais as $notaFiscalId) {
                        NotasFiscaisAvaria::create([
                            'avaria_id' => $avaria->id,
                            'nota_fiscal_id' => $notaFiscalId,
                            'usuario_responsavel_id' => $usuarioId,
                        ]);
                    }
                }

                // 3. Associa os Produtos
                if ($request->has('produtos')) {
                    foreach ($request->produtos as $produto) {
                        ProdutosAvaria::create([
                            'avaria_id' => $avaria->id,
                            'produto_id' => $produto['produto_id'],
                            'tipo_avaria_id' => $produto['tipo_avaria_id'],
                            'quantidade' => $produto['quantidade'],
                            'usuario_responsavel_id' => $usuarioId,
                        ]);
                    }
                }

                // 4. Salva os Anexos
                if ($request->has('anexos')) {
                    foreach ($request->anexos as $caminhoAnexo) {
                        AnexosAvaria::create([
                            'avaria_id' => $avaria->id,
                            'path' => $caminhoAnexo,
                            'usuario_responsavel_id' => $usuarioId,
                        ]);
                    }
                }

                return $avaria;
            });

            // Carrega os relacionamentos para retornar na resposta
            $avaria->load(['notasFiscais', 'produtos', 'anexos']);

            return response()->json([
                'success' => true,
                'message' => 'Avaria registrada com sucesso.',
                'data' => $avaria
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao registrar avaria: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao processar o registro da avaria.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
