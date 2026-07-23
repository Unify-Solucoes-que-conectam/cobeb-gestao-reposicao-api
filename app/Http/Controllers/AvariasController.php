<?php

namespace App\Http\Controllers;

use App\Http\Resources\AvariaResource;
use App\Models\AnexosAvaria;
use App\Models\Avaria;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\WhatsAppService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
                });
            }

            $avarias = $query->with([
                'cliente',
                'aprovador',
                'anexos',
                'itens',
                'itens.produtoNotaFiscal',
                'itens.produtoNotaFiscal.produto',
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
        try {
            // Inicia a transação
            $avaria = DB::transaction(function () use ($request) {

                $validator = Validator::make($request->all(), Avaria::createRules(), Avaria::messages());

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro de validação.',
                        'errors' => $validator->errors()
                    ], 422);
                }

                // Cria a Avaria principal
                $avaria = Avaria::create([
                    ...$validator->validated(),
                    'status' => 'pendente',
                    'data_emissao' => now(),
                ]);

                $produtos = $request->input('produtos');

                // Mapeia os campos da requisição para as colunas do banco
                $itensData = array_map(function ($produto) {
                    return [
                        'produto_nota_fiscal_id' => $produto['produto_id'],
                        'tipo_avaria_id'         => $produto['tipo_avaria_id'],
                        'quantidade_avariada'    => $produto['quantidade'],
                    ];
                }, $produtos);

                // O Laravel já atribui o 'avaria_id' automaticamente para todos
                $avaria->itens()->createMany($itensData);

                // Salva os Anexos
                if ($request->has('anexos')) {
                    foreach ($request->anexos as $anexo) {

                        // Pega o nome do frontend, ou usa um genérico se falhar
                        $nomeOriginal = $anexo['nome'] ?? 'anexo_sem_nome.jpg';
                        $base64Anexo = $anexo['base64'];

                        // 1. Descobre a extensão real baseada no cabeçalho base64 do frontend
                        $extensaoReal = 'jpg'; // fallback
                        if (preg_match('/^data:image\/([a-zA-Z0-9]+);base64,/', $base64Anexo, $type)) {
                            $extensaoReal = strtolower($type[1]);
                        } else {
                            // Se não tiver cabeçalho, tenta extrair a extensão do nome original
                            $extensaoReal = pathinfo($nomeOriginal, PATHINFO_EXTENSION) ?: 'jpg';
                        }

                        // 2. Remove a parte "data:image/png;base64," do começo da string
                        if (strpos($base64Anexo, ',') !== false) {
                            $base64Anexo = explode(',', $base64Anexo)[1];
                        }

                        // 3. Corrige possíveis espaços em branco que quebram o base64 e decodifica
                        $base64Anexo = str_replace(' ', '+', $base64Anexo);
                        $anexoDecodificado = base64_decode($base64Anexo);

                        if ($anexoDecodificado === false) {
                            continue; // Pula para a próxima foto se o arquivo estiver corrompido
                        }

                        // 4. Formata um nome de arquivo seguro usando o nome original
                        $nomeSemExtensao = pathinfo($nomeOriginal, PATHINFO_FILENAME);
                        $nomeLimpo = \Illuminate\Support\Str::slug($nomeSemExtensao); // Remove acentos e espaços

                        // Exemplo do resultado final: 650a1b2c_foto-da-garrafa.png
                        $nomeArquivo = uniqid() . '_' . $nomeLimpo . '.' . $extensaoReal;
                        $caminhoAnexo = 'anexos_avarias/' . $nomeArquivo;

                        // Salva fisicamente o arquivo
                        Storage::disk('public')->put($caminhoAnexo, $anexoDecodificado);

                        // 5. Salva no banco de dados
                        AnexosAvaria::create([
                            'avaria_id' => $avaria->id,
                            'path' => $caminhoAnexo,
                        ]);
                    }
                }

                return $avaria;
            });

            if ($avaria instanceof JsonResponse) {
                return $avaria; // Retorna o erro de validação se houver
            }

            return response()->json([
                'success' => true,
                'message' => 'Avaria registrada com sucesso.',
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
            $sent = $whatsapp->sendMessage('37991946275', "Olá {$avaria->cliente->nome_fantasia}, sua avaria foi {$request->status} com sucesso.");

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
