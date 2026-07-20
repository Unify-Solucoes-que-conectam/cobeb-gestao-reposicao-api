<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAvariaRequest;
use App\Http\Resources\AvariaResource;
use App\Models\AnexosAvaria;
use App\Models\Avaria;
use App\Models\NotasFiscaisAvaria;
use App\Models\ProdutosAvaria;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\WhatsAppService;
use Illuminate\Support\Facades\Storage;

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
                'anexos',
                'cliente',
                'mapa',
                'mapa.motorista.filial',
                'mapa.motorista.cluster',
                'notasFiscais.nota_fiscal',
                'notasFiscais.avaria.produtos',
                'notasFiscais.nota_fiscal.produtos.produto',
                'notasFiscais.nota_fiscal.produtos.produto.tipoMarca',
                'notasFiscais.nota_fiscal.produtos.produto.embalagem',
            ])->get();

            // filtra avarias pelo clienteId
            if ($request->has('clienteId')) {
                $avarias = $avarias->where('cliente_id', $request->clienteId);
            }

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
                    foreach ($request->anexos as $anexo) {

                        // Decodifica o arquivo base64
                        $anexoDecodificado = base64_decode($anexo['base64']);

                        // Salva o arquivo em storage/app/public/anexos_avarias
                        $nomeArquivo = uniqid('anexo_') . '.jpg';
                        $caminhoAnexo = 'anexos_avarias/' . $nomeArquivo;
                        Storage::disk('public')->put($caminhoAnexo, $anexoDecodificado);

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

    /**
     * Atualiza os dados de uma avaria existente.
     */
    public function update(StoreAvariaRequest $request, string $id)
    {
        try {

            // 1. Valida se os campos obrigatórios estão presentes
            $request->validate([
                'produto_id' => 'required|exists:produtos,id',
                'quantidade_avariada' => 'required|integer|min:1',
            ]);

            $avaria = Avaria::findOrFail($id);

            // Atualiza os campos da avaria
            $avaria->update($request->only(['cliente_id', 'mapa_id']));

            return response()->json([
                'success' => true,
                'message' => 'Avaria atualizada com sucesso.',
                'data' => $avaria
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar avaria: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao atualizar a avaria.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aprova ou reprova uma avaria.
     *
     * @param Request $request
     * @param string $id UUID da Avaria
     * @return JsonResponse
     */
    public function updateStatus(Request $request, string $id)
    {
        // 1. Valida se o status enviado é estritamente 'aprovada' ou 'reprovada'
        $request->validate([
            'status' => ['required', 'string', 'in:aprovada,reprovada'],
        ], [
            'status.in' => 'O status deve ser apenas aprovada ou reprovada.'
        ]);

        try {
            // 2. Busca a avaria pelo ID
            $avaria = Avaria::findOrFail($id);

            // 3. Regra de negócio: só pode alterar se estiver 'pendente'
            if (strtolower($avaria->status) !== 'pendente') {
                return response()->json([
                    'success' => false,
                    'message' => "Não é possível alterar o status. A avaria atual encontra-se como '{$avaria->status}'."
                ], 400); // 400 Bad Request
            }

            // 4. Atualiza o status e salva
            $avaria->status = $request->status;
            $avaria->save(); //

            // Enviar via WhatsApp
            $whatsapp = new WhatsAppService();
            $sent = $whatsapp->sendMessage('37998247669', "Olá {$avaria->cliente->nome_fantasia}, sua avaria foi {$request->status} com sucesso. Agradecemos pela sua paciência e compreensão.");

            if (!$sent) {
                Log::warning("Falha ao enviar mensagem de WhatsApp para o cliente {$avaria->cliente->nome_fantasia} (ID: {$avaria->cliente->id}).");
                return response()->json([
                    'success' => false,
                    'message' => "Avaria {$request->status} com sucesso, mas falha ao enviar notificação via WhatsApp."
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => "Avaria {$request->status} com sucesso, o cliente foi notificado via WhatsApp.",
                'data' => $avaria
            ]);
        } catch (ModelNotFoundException $e) {
            // Caso o ID (UUID) enviado não exista no banco
            return response()->json([
                'success' => false,
                'message' => 'Avaria não encontrada.'
            ], 404);
        } catch (\Exception $e) {
            // Tratamento de erros gerais
            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao atualizar o status da avaria.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
