<?php

namespace App\Http\Controllers;

use App\Http\Resources\AvariaResource;
use App\Jobs\ProcessarRelatorioAvariaJob;
use App\Models\AnexosAvaria;
use App\Models\Avaria;
use App\Models\ProdutoNotaFiscal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\WhatsAppService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AvariasController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Avaria::query();

            if ($request->has('search')) {

                // consultar pelo nome ou código do cliente
                $query->orWhereHas('cliente', function ($q) use ($request) {
                    $q->where('nome_fantasia', 'like', '%' . $request->search . '%')->orWhere('codigo', 'like', '%' . $request->search . '%');
            if ($request->has('search')) {

                // consultar pelo nome ou código do cliente
                $query->orWhereHas('cliente', function ($q) use ($request) {
                    $q->where('nome_fantasia', 'like', '%' . $request->search . '%')->orWhere('codigo', 'like', '%' . $request->search . '%');
                });
            }

            $avarias = $query->with([
                'cliente',
                'motorista',
                'motorista.mapaAtual',
                'motorista',
                'motorista.mapaAtual',
                'motorista.cluster',
                'motorista.filial',
                'aprovador',
                'anexos',
                'itens',
                'itens.produtoNotaFiscal',
                'itens',
                'itens.produtoNotaFiscal',
                'itens.produtoNotaFiscal.produto',
                'itens.produtoNotaFiscal.notaFiscal',
                'itens.tipoAvaria',
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

    public function store(Request $request)
    {
        // 1. Validação fora da transação para economizar recursos de banco
        $validator = Validator::make($request->all(), Avaria::createRules(), Avaria::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors()
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
                        ->first();

                    // Se não encontrou, cria a Avaria principal
                    if (!$avaria) {
                        $avaria = Avaria::create([
                            ...$validated,
                            'status' => 'pendente',
                            'data_emissao' => now(),
                            'data_emissao' => now(),
                        ]);
                    }

                    // 4. Processa os itens desta Nota Fiscal
                    foreach ($produtosDaNota as $prodReq) {

                        // Verifica se já existe o MESMO PRODUTO com o MESMO TIPO DE AVARIA
                        $itemExistente = $avaria->itens()
                            ->where('produto_nota_fiscal_id', $prodReq['produto_id'])
                            ->where('tipo_avaria_id', $prodReq['tipo_avaria_id'])
                            ->first();

                        if ($itemExistente) {
                            // Se existir, APENAS ATUALIZA (Estou somando a quantidade nova com a que já existia)
                            // Obs: Se a regra for sobrescrever em vez de somar, troque += por =
                            $itemExistente->quantidade_avariada += $prodReq['quantidade'];
                            $itemExistente->save();
                        } else {
                            // Se for tipo diferente, ou um produto novo, CRIA UM NOVO ITEM
                            $avaria->itens()->create([
                                'produto_nota_fiscal_id' => $prodReq['produto_id'],
                                'tipo_avaria_id'         => $prodReq['tipo_avaria_id'],
                                'quantidade_avariada'    => $prodReq['quantidade'],
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
                            } else {
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
                            $nomeLimpo = \Illuminate\Support\Str::slug($nomeSemExtensao);

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

            if (!$resultado->isEmpty()) {

                $avarias = $resultado;

                if ($avarias->isEmpty()) {
                    return response()->json(['message' => 'Nenhuma avaria processada.'], 400);
                }

                $avariaPrincipal = $avarias->first()->load([
                    'itens.produtoNotaFiscal.notaFiscal',
                    'itens.tipoAvaria',
                    'cliente',
                    'cliente.contatos'
                ]);

                $clienteModel = $avariaPrincipal->cliente;
                $enderecoCompleto = $clienteModel->endereco . ', ' . $clienteModel->bairro . ', ' . $clienteModel->cidade . ' - ' . $clienteModel->uf . ', CEP: ' . $clienteModel->cep;
                $contatoCliente = $clienteModel->contatos->where('isWhatsapp', true)->first() ?? null;

                if (!$contatoCliente) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Avaria registrada/atualizada, mas não foi possível enviar relatório via WhatsApp. Contato do cliente não cadastrado.',
                    ], 404);
                }

                $tipoDocumento = Str::length($clienteModel->documento ?? '') === 11 ? 'cpf' : 'cnpj';

                $cliente = (object)[
                    'nome'     => $clienteModel->razao_social ?? $clienteModel->nome,
                    $tipoDocumento => $clienteModel->documento ?? '',
                    'endereco' => $enderecoCompleto ?? 'Endereço não cadastrado',
                    'telefone' => $contatoCliente->numero ?? 'N/A'
                ];

                $protocolo = 'AVR-' . $avariaPrincipal->id;

                // Dispara o Job para processar o PDF e o WhatsApp em segundo plano
                ProcessarRelatorioAvariaJob::dispatch($avarias, $cliente, $contatoCliente, $protocolo);
            }

            return response()->json([
                'success' => true,
                'message' => 'Avaria registrada/atualizada com sucesso.',
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
     * Atualiza a quantidade de um produto específico em uma avaria (via Nota Fiscal).
     */
    // public function updateQuantidadeProduto(Request $request, string $avariaId, string $produtoId)
    // {
    //     // Valida se a nova quantidade foi enviada e é um número válido
    //     $request->validate([
    //         'quantidade' => 'required|integer|min:1',
    //     ]);
    // public function updateQuantidadeProduto(Request $request, string $avariaId, string $produtoId)
    // {
    //     // Valida se a nova quantidade foi enviada e é um número válido
    //     $request->validate([
    //         'quantidade' => 'required|integer|min:1',
    //     ]);

    //     try {
    //         // 1. Descobre os IDs de todas as notas fiscais vinculadas a esta avaria
    //         $notasFiscaisIds = NotasFiscaisAvaria::where('avaria_id', $avariaId)
    //             ->pluck('nota_fiscal_id');

    //         // 2. Busca o registro do produto cruzando com as notas fiscais encontradas
    //         $produtoNota = \App\Models\ProdutoNotaFiscal::whereIn('nota_fiscal_id', $notasFiscaisIds)
    //             ->where('produto_id', $produtoId)
    //             ->firstOrFail();
    //     try {
    //         // 1. Descobre os IDs de todas as notas fiscais vinculadas a esta avaria
    //         $notasFiscaisIds = NotasFiscaisAvaria::where('avaria_id', $avariaId)
    //             ->pluck('nota_fiscal_id');

    //         // 2. Busca o registro do produto cruzando com as notas fiscais encontradas
    //         $produtoNota = \App\Models\ProdutoNotaFiscal::whereIn('nota_fiscal_id', $notasFiscaisIds)
    //             ->where('produto_id', $produtoId)
    //             ->firstOrFail();

    //         // 3. Atualiza a quantidade correta na tabela produtos_nota_fiscal
    //         $produtoNota->update([
    //             'quantidade' => $request->quantidade,
    //             'usuario_responsavel_id' => $request->user()->id // Opcional, para rastrear quem editou
    //         ]);
    //         // 3. Atualiza a quantidade correta na tabela produtos_nota_fiscal
    //         $produtoNota->update([
    //             'quantidade' => $request->quantidade,
    //             'usuario_responsavel_id' => $request->user()->id // Opcional, para rastrear quem editou
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Quantidade atualizada com sucesso.',
    //             'data' => $produtoNota
    //         ], 200);
    //     } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Produto não encontrado nas notas fiscais desta avaria.'
    //         ], 404);
    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('Erro ao atualizar quantidade do produto na avaria: ' . $e->getMessage());
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Quantidade atualizada com sucesso.',
    //             'data' => $produtoNota
    //         ], 200);
    //     } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Produto não encontrado nas notas fiscais desta avaria.'
    //         ], 404);
    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('Erro ao atualizar quantidade do produto na avaria: ' . $e->getMessage());

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Ocorreu um erro ao atualizar a quantidade.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Ocorreu um erro ao atualizar a quantidade.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

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
            'status' => ['required', 'string', 'in:aprovada,reprovada,enviada,trocada'],
            'status' => ['required', 'string', 'in:aprovada,reprovada,enviada,trocada'],
        ], [
            'status.in' => 'O status deve ser apenas aprovada, reprovada, enviada ou trocada.'
            'status.in' => 'O status deve ser apenas aprovada, reprovada, enviada ou trocada.'
        ]);

        try {
            // 2. Busca a avaria pelo ID
            $avaria = Avaria::findOrFail($id);

            // 3. Regra de negócio: só pode alterar se estiver 'pendente'
            if (strtolower($avaria->status) !== 'pendente') {
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
            $sent = $whatsapp->sendMessage('37991946275', "Olá {$avaria->cliente->nome_fantasia}, sua avaria foi {$request->status} com sucesso.");
            $sent = $whatsapp->sendMessage('37991946275', "Olá {$avaria->cliente->nome_fantasia}, sua avaria foi {$request->status} com sucesso.");

            if (!$sent) {
                Log::warning("Falha ao enviar mensagem de WhatsApp para o cliente {$avaria->cliente->nome_fantasia} (ID: {$avaria->cliente->id}).");
                return response()->json([
                    'success' => false,
                    'message' => "Avaria {$request->status} com sucesso, mas falha ao enviar notificação via WhatsApp."
                    'message' => "Avaria {$request->status} com sucesso, mas falha ao enviar notificação via WhatsApp."
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => "Avaria {$request->status} com sucesso, o cliente foi notificado via WhatsApp.",
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
