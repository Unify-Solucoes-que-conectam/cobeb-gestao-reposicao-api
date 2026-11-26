<?php

namespace App\Http\Controllers;

use App\Support\Tenant;
use App\Http\Resources\AvariaResource;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\MapaResource;
use App\Models\Avaria;
use App\Models\Cliente;
use App\Models\ClientesMapa;
use App\Models\Mapa;
use App\Models\Motorista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MapasController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Mapa::query();

            // filtro por código
            if ($request->filled('search')) {
                $query->where('codigo', 'like', '%' . $request->input('search') . '%');
            }

            // filial: no tenant "Geral" usa o filtro da tela; numa filial, sempre a filial conectada
            Tenant::atual()->restringir($query, 'filial_id', $request->input('filial'));

            $query->with([
                'clientes',
                'clientes.cliente',
                'motorista',
                'motorista.filial',
                'motorista.cluster',
                'filial'
            ])->orderByDesc('data_entrega')->orderBy('codigo')->orderBy('id');

            // paginado quando a tela envia "per_page"
            return static::paginarOuListar($request, $query, fn ($mapa) => new MapaResource($mapa), 'Mapas carregados com sucesso.');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar mapas.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show(Mapa $mapa)
    {
        try {
            $mapa->load([
                'clientes',
                'clientes.cliente',
                'motorista.filial',
                'motorista.cluster'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mapa carregado com sucesso.',
                'data'    => new MapaResource($mapa)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar mapa.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function clientes(Request $request, string $mapaId)
    {
        try {
            $user = $request->user();
            $query = ClientesMapa::query();

            // Filtra o mapa pelo ID fornecido
            $query->where('mapa_id', $mapaId);

            $clientesMapa = ClientesMapa::where('mapa_id', $mapaId)
                // retorna apenas os clientes do mapa que possuem data de entrega igual a hoje, caso o usuário seja um motorista
                ->when($user->role === 'motorista', fn($query) => $query->whereHas('mapa', function ($query) {
                    $query->where('data_entrega', '=', today());
                }))
                ->with([
                    // Usamos um array no 'cliente' para injetar o withCount e carregar as outras relações
                    'cliente' => function ($query) {
                        $query->withCount('notasFiscais') // Traz o número de notas sem carregar todos os registros
                            ->with(['filial', 'categoria', 'contatos']);
                    }
                ])->get();

            // adicionar demais filtros na requisição
            if ($request->has('search')) {
                $search = $request->input('search');
                $clientesMapa = $clientesMapa->filter(function ($clienteMapa) use ($search) {
                    return str_contains(strtolower($clienteMapa->cliente->nome_fantasia), strtolower($search)) ||
                           str_contains(strtolower($clienteMapa->cliente->razao_social), strtolower($search)) ||
                            str_contains(strtolower($clienteMapa->cliente->codigo), strtolower($search));
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Clientes vinculados ao mapa carregados com sucesso.',
                'data'    => ClienteResource::collection($clientesMapa->pluck('cliente'))
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar clientes vinculados ao mapa.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function avarias(Request $request, string $mapaId)
    {
        try {
            // 1. Encontra todos os IDs de clientes associados ao mapa fornecido
            $clienteIds = ClientesMapa::where('mapa_id', $mapaId)->pluck('cliente_id');

            // 2. Filtra as avarias onde o cliente_id está dentro da lista de clientes do mapa
            $query = Avaria::query()->whereIn('cliente_id', $clienteIds);

            // 3. Carrega os relacionamentos (mantendo a sua lógica original)
            $avariasMapa = $query->with([
                'itens',
                'itens.produtoNotaFiscal' => function ($query) {
                    $query->withCount('produto') // Traz a quantidade de produtos
                        ->with(['produto.tipoMarca', 'produto.embalagem']);
                }
            ])->get();

            return response()->json([
                'success' => true,
                'message' => 'Avarias vinculadas ao mapa carregadas com sucesso.',
                'data'    => AvariaResource::collection($avariasMapa)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar avarias vinculadas ao mapa.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Designa ou troca o motorista responsável pelo mapa.
     * Regra: um mapa que já possui motorista pode ser alterado para outro motorista.
     */
    public function designarMotorista(Request $request, string $mapaId, string $motoristaId)
    {
        try {
            $mapa = Mapa::find($mapaId);
            if (!$mapa) {
                return response()->json(['success' => false, 'message' => 'Mapa não encontrado.'], 404);
            }

            $motorista = Motorista::find($motoristaId);
            if (!$motorista) {
                return response()->json(['success' => false, 'message' => 'Motorista não encontrado.'], 404);
            }

            // o motorista precisa ser da mesma revenda (filial) do mapa
            if ($mapa->filial_id && $motorista->filial_id !== $mapa->filial_id) {
                return response()->json(['success' => false, 'message' => 'O motorista não pertence à filial do mapa.'], 422);
            }

            if ($motorista->status !== 'ativo') {
                return response()->json(['success' => false, 'message' => 'Apenas motoristas ativos podem ser designados.'], 422);
            }

            $trocou = $mapa->motorista_id !== null && $mapa->motorista_id !== $motorista->id;

            $mapa->motorista_id = $motorista->id;
            $mapa->save();

            return response()->json([
                'success' => true,
                'message' => $trocou ? 'Motorista do mapa alterado com sucesso.' : 'Motorista designado ao mapa com sucesso.',
                'data'    => new MapaResource($mapa->load(['motorista', 'filial'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao designar motorista ao mapa.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Substitui a lista de clientes vinculados ao mapa.
     */
    public function atualizarClientes(Request $request, string $mapaId)
    {
        $mapa = Mapa::find($mapaId);
        if (!$mapa) {
            return response()->json(['success' => false, 'message' => 'Mapa não encontrado.'], 404);
        }

        $dados = $request->validate([
            'clientes'   => ['present', 'array'],
            'clientes.*' => ['string', 'distinct', 'exists:clientes,id'],
        ], [
            'clientes.*.exists' => 'Cliente não encontrado.',
        ]);

        $clienteIds = $dados['clientes'];

        // os clientes precisam ser da mesma revenda (filial) do mapa
        if ($mapa->filial_id) {
            $deOutraFilial = Cliente::query()
                ->whereIn('id', $clienteIds)
                ->whereNotNull('filial_id')
                ->where('filial_id', '!=', $mapa->filial_id)
                ->exists();

            if ($deOutraFilial) {
                return response()->json(['success' => false, 'message' => 'Há clientes que não pertencem à filial do mapa.'], 422);
            }
        }

        try {
            DB::transaction(function () use ($mapa, $clienteIds) {
                ClientesMapa::query()
                    ->where('mapa_id', $mapa->id)
                    ->whereNotIn('cliente_id', $clienteIds)
                    ->delete();

                foreach ($clienteIds as $clienteId) {
                    ClientesMapa::firstOrCreate(['mapa_id' => $mapa->id, 'cliente_id' => $clienteId]);
                }
            });

            $mapa->load(['clientes.cliente', 'motorista', 'filial']);

            return response()->json([
                'success' => true,
                'message' => 'Clientes do mapa atualizados com sucesso.',
                'data'    => new MapaResource($mapa),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar os clientes do mapa.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
