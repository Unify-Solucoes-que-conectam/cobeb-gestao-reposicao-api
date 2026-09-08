<?php

namespace App\Http\Controllers;

use App\Events\GlobalEvent;
use App\Exceptions\EvolutionException;
use App\Exceptions\WhatsAppNotConfiguredException;
use App\Exceptions\WhatsAppNumberNotFoundException;
use App\Http\Resources\AvariaResource;
use App\Http\Resources\ItemAvariaResource;
use App\Models\AnexosAvaria;
use App\Models\Avaria;
use App\Models\ItemAvaria;
use App\Models\ProdutoNotaFiscal;
use App\Support\AvariaWhatsAppNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AvariasController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Avaria::query();

            // 1. Busca agrupada (Isola os ORs dentro de parênteses lógicos)
            if ($request->filled('search')) {
                $search = $request->input('search');

                // Onde (where) inicia o bloco ( )
                $query->where(function ($q) use ($search) {
                    $q->whereHas('cliente', function ($qCliente) use ($search) {
                        $qCliente->where('nome_fantasia', 'like', '%' . $search . '%')
                            ->orWhere('codigo', 'like', '%' . $search . '%')
                        ;
                    })
                        ->orWhere('id', 'like', '%' . $search . '%') // Fecha o bloco ( )
                    ;
                });
            }

            // 2. Filtro de Status
            // O filled garante que só filtra se vier um status válido, ignorando strings vazias
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // 3. Filtro de Filial
            if ($request->filled('filialId')) {
                $filialId = $request->input('filialId');
                $query->whereHas('motorista.filial', function ($q) use ($filialId) {
                    $q->where('id', $filialId);
                });
            }

            $avarias = $query->with([
                'anexos',
                'cliente',
                'cliente.contatos',
                'whatsappNotifications',
                'motorista.mapas',
                'motorista.cluster',
                'motorista.filial',
                'aprovador',
                'anexos',
                'itens.produtoNotaFiscal.produto',
                'itens.produtoNotaFiscal.notaFiscal',
                'itens.tipoAvaria',
            ])->where('status', '!=', 'pendente')->get();

            return response()->json([
                'success' => true,
                'message' => 'Avarias carregadas com sucesso.',
                'data' => AvariaResource::collection($avarias),
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar avarias.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        // 1. Validação fora da transação para economizar recursos de banco
        $validator = Validator::make($request->all(), Avaria::createRules(), Avaria::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Inicia a transação
            $resultado = DB::transaction(function () use ($request, $validator) {
                $validated = $validator->validated();
                $produtosInput = $request->input('produtos');

                // 2. Agrupa os produtos da requisição por nota_fiscal_id
                // Isso garante que se o motorista mandar produtos de notas diferentes na mesma requisição,
                // o sistema crie/atualize as avarias separadamente.
                $produtosPorNota = [];

                foreach ($produtosInput as $produtoReq) {
                    // Importante: ajuste o namespace '\App\Models\ProdutoNotaFiscal' se o seu for diferente
                    $produtoNota = ProdutoNotaFiscal::find($produtoReq['produto_id']);

                    if ($produtoNota) {
                        $produtosPorNota[$produtoNota->nota_fiscal_id][] = $produtoReq;
                    }
                }

                $avariasProcessadas = [];

                // 3. Processa a regra de negócio para cada Nota Fiscal encontrada
                foreach ($produtosPorNota as $notaFiscalId => $produtosDaNota) {
                    // Busca se JÁ EXISTE uma avaria PENDENTE para este cliente e esta Nota
                    $avaria = Avaria::where('cliente_id', $validated['cliente_id'])
                        ->where('status', 'pendente') // Evita alterar avarias já aprovadas/fechadas
                        ->whereHas('itens.produtoNotaFiscal', function ($q) use ($notaFiscalId) {
                            $q->where('nota_fiscal_id', $notaFiscalId);
                        })
                        ->first()
                    ;

                    // Se não encontrou, cria a Avaria principal
                    if (!$avaria) {
                        $avaria = Avaria::create([
                            ...$validated,
                            'status' => 'pendente',
                            'data_emissao' => now(),
                        ]);
                    }

                    // 4. Processa os itens desta Nota Fiscal
                    foreach ($produtosDaNota as $prodReq) {
                        // Verifica se já existe o MESMO PRODUTO com o MESMO TIPO DE AVARIA
                        $itemExistente = $avaria->itens()
                            ->where('produto_nota_fiscal_id', $prodReq['produto_id'])
                            ->where('tipo_avaria_id', $prodReq['tipo_avaria_id'])
                            ->first()
                        ;

                        if ($itemExistente) {
                            // Se existir, APENAS ATUALIZA (Estou somando a quantidade nova com a que já existia)
                            // Obs: Se a regra for sobrescrever em vez de somar, troque += por =
                            $itemExistente->quantidade_avariada += $prodReq['quantidade'];
                            $itemExistente->save();
                        }
                        else {
                            // Se for tipo diferente, ou um produto novo, CRIA UM NOVO ITEM
                            $avaria->itens()->create([
                                'produto_nota_fiscal_id' => $prodReq['produto_id'],
                                'tipo_avaria_id' => $prodReq['tipo_avaria_id'],
                                'quantidade_avariada' => $prodReq['quantidade'],
                            ]);
                        }
                    }

                    // 5. Salva os Anexos vinculados à Avaria (seja ela nova ou existente)
                    if ($request->has('anexos')) {
                        foreach ($request->anexos as $anexo) {
                            $nomeOriginal = $anexo['nome'] ?? 'anexo_sem_nome.jpg';
                            $base64Anexo = $anexo['base64'];

                            $extensaoReal = 'jpg';

                            if (preg_match('/^data:image\/([a-zA-Z0-9]+);base64,/', $base64Anexo, $type)) {
                                $extensaoReal = strtolower($type[1]);
                            }
                            else {
                                $extensaoReal = pathinfo($nomeOriginal, PATHINFO_EXTENSION) ?: 'jpg';
                            }

                            if (strpos($base64Anexo, ',') !== false) {
                                $base64Anexo = explode(',', $base64Anexo)[1];
                            }

                            $base64Anexo = str_replace(' ', '+', $base64Anexo);
                            $anexoDecodificado = base64_decode($base64Anexo);

                            if ($anexoDecodificado === false) {
                                continue;
                            }

                            $nomeSemExtensao = pathinfo($nomeOriginal, PATHINFO_FILENAME);
                            $nomeLimpo = Str::slug($nomeSemExtensao);

                            $nomeArquivo = uniqid() . '_' . $nomeLimpo . '.' . $extensaoReal;
                            $caminhoAnexo = 'anexos_avarias/' . $nomeArquivo;

                            Storage::disk('public')->put($caminhoAnexo, $anexoDecodificado);

                            AnexosAvaria::create([
                                'avaria_id' => $avaria->id,
                                'path' => $caminhoAnexo,
                            ]);
                        }
                    }

                    $avariasProcessadas[] = $avaria;
                }

                return collect($avariasProcessadas);
            });

            return response()->json([
                'success' => true,
                'message' => 'Avaria registrada/atualizada com sucesso.',
            ], 201);
        }
        catch (\Exception $e) {
            Log::error('Erro ao registrar avaria: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao processar o registro da avaria.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Atualiza a quantidade de um produto específico em uma avaria (via Nota Fiscal).
     */
    public function updateQuantidadeProduto(Request $request, string $avariaId, string $itemId)
    {
        // Valida se a nova quantidade foi enviada e é um número válido
        $request->validate([
            'quantidade' => 'required|integer|min:1',
        ]);

        try {
            $itemAvaria = ItemAvaria::where('avaria_id', $avariaId)
                ->where('id', $itemId)
                ->firstOrFail()
            ;

            // validar se a quantidade enviada é menor ou igual à quantidade original do produto na nota fiscal
            $produtoNotaFiscal = ProdutoNotaFiscal::find($itemAvaria->produto_nota_fiscal_id);

            if (!$produtoNotaFiscal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produto não encontrado na nota fiscal.',
                ], 404);
            }

            if ($request->quantidade > $produtoNotaFiscal->quantidade) {
                return response()->json([
                    'success' => false,
                    'message' => 'A quantidade avariada não pode ser maior que a quantidade original na nota fiscal.',
                ], 400);
            }

            $itemAvaria->quantidade_avariada = $request->quantidade;
            $itemAvaria->save();

            return response()->json([
                'success' => true,
                'message' => 'Quantidade atualizada com sucesso.',
            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produto não encontrado nas notas fiscais desta avaria.',
            ], 404);
        }
        catch (\Exception $e) {
            Log::error('Erro ao atualizar quantidade do produto na avaria: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao atualizar a quantidade.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aprova ou reprova uma avaria.
     *
     * @param  string  $id  UUID da Avaria
     * @return JsonResponse
     */
    public function updateStatus(Request $request, string $id)
    {
        // 1. Valida se o status enviado é estritamente 'aprovada' ou 'reprovada'
        // 1. Valida se o status enviado é estritamente 'aprovada' ou 'reprovada'
        $request->validate([
            'status' => ['required', 'string', 'in:aprovada,reprovada,aguardando_aprovacao,trocada'],
            'motivo_reprovacao' => ['nullable', 'string', 'max:255'],
        ], [
            'status.in' => 'O status deve ser apenas aprovada, reprovada, aguardando aprovação ou trocada.',
        ]);

        try {
            // 2. Busca a avaria pelo ID
            $avaria = Avaria::findOrFail($id);

            // 4. Atualiza o status e salva
            $avaria->status = $request->status;

            // adicionar dados do aprovador/reprovador de acordo com o status
            if ($request->status === 'aprovada') {
                $avaria->aprovador_id = $request->user()->id;
                $avaria->data_aprovacao = now();
            }
            elseif ($request->status === 'reprovada') {
                $avaria->aprovador_id = $request->user()->id;
                $avaria->data_aprovacao = now();

                if (empty($request->motivo_reprovacao)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'O motivo da reprovação é obrigatório.',
                    ], 400);
                }

                $avaria->motivo_reprovacao = $request->motivo_reprovacao;
            }
            $avaria->save();

            if ($request->status === 'aguardando_aprovacao') {
                event(new GlobalEvent([
                    'titulo' => 'Nova avaria registrada',
                    'mensagem' => 'Uma nova avaria foi registrada/atualizada para o cliente: ' . $avaria->cliente->nome_fantasia,
                    'tipo' => 'info',
                    'data_envio' => now(),
                    'lida' => false,
                    'link' => null,
                ]));
            }

            $notification = app(AvariaWhatsAppNotificationService::class)
                ->queueForCurrentStatus($avaria->fresh(), $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => $notification
                    ? "Avaria atualizada para {$request->status}. A notificação será processada pela fila do WhatsApp."
                    : "Avaria atualizada para {$request->status}.",
                'data' => [
                    'avaria' => $avaria,
                    'whatsapp_notification' => $notification,
                ],
            ]);
        }
        catch (\Exception $e) {
            // Tratamento de erros gerais
            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao atualizar o status da avaria.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateWhatsAppContact(
        Request $request,
        string $id,
        AvariaWhatsAppNotificationService $notifications,
    ): JsonResponse {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $avaria = Avaria::with(['cliente.contatos', 'motorista'])->findOrFail($id);

        try {
            $notification = $notifications->validateContactAndRetry(
                $avaria,
                $validated['phone'],
                $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Número validado. A notificação foi reenfileirada.',
                'data' => $notification,
            ], 202);
        }
        catch (WhatsAppNumberNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => 'WHATSAPP_NUMBER_NOT_FOUND',
                'data' => null,
            ], 422);
        }
        catch (WhatsAppNotConfiguredException $exception) {
            $code = str_contains($exception->getMessage(), 'conectado')
                ? 'WHATSAPP_DISCONNECTED'
                : 'WHATSAPP_NOT_CONFIGURED';

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $code,
                'data' => null,
            ], 409);
        }
        catch (EvolutionException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Não foi possível validar o número no WhatsApp agora.',
                'error_code' => 'EVOLUTION_UNAVAILABLE',
                'data' => null,
            ], 503);
        }
        catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => 'WHATSAPP_PHONE_INVALID',
                'data' => null,
            ], 422);
        }
        catch (\RuntimeException $exception) {
            $alreadyQueued = $exception->getMessage() === 'WHATSAPP_ALREADY_QUEUED';

            return response()->json([
                'success' => false,
                'message' => $alreadyQueued
                    ? 'Esta notificação já está em processamento.'
                    : $exception->getMessage(),
                'error_code' => $alreadyQueued
                    ? 'WHATSAPP_ALREADY_QUEUED'
                    : 'WHATSAPP_NOTIFICATION_NOT_PENDING',
                'data' => null,
            ], 409);
        }
    }

    public function itens(Request $request, string $id)
    {
        try {
            $avaria = Avaria::with([
                'itens',
                'itens.produtoNotaFiscal',
                'itens.produtoNotaFiscal.produto',
                'itens.tipoAvaria',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Itens da avaria carregados com sucesso.',
                'data' => ItemAvariaResource::collection($avaria->itens),
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar itens da avaria.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $avaria = Avaria::findOrFail($id);

            // Verifica se a avaria está em status 'pendente'
            if ($avaria->status !== 'pendente') {
                return response()->json([
                    'success' => false,
                    'message' => "Não é possível deletar a avaria. O status atual é '{$avaria->status}'.",
                ], 400);
            }

            // Deleta os anexos associados à avaria
            foreach ($avaria->anexos as $anexo) {
                Storage::disk('public')->delete($anexo->path);
                $anexo->delete();
            }

            // Deleta os itens da avaria
            $avaria->itens()->delete();

            // Deleta a avaria
            $avaria->delete();

            return response()->json([
                'success' => true,
                'message' => 'Avaria deletada com sucesso.',
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao deletar a avaria.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
