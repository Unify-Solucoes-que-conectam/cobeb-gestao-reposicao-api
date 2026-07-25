<?php

namespace App\Http\Controllers;

use App\Http\Resources\AvariaResource;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\MapaResource;
use App\Models\Avaria;
use App\Models\ClientesMapa;
use App\Models\Mapa;
use Illuminate\Http\Request;

class MapasController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Mapa::query();

            if ($request->has('search')) {
                $query->Where('codigo', 'like', '%' . $request->input('search') . '%');
            }

            $mapas = $query->with([
                'clientes.cliente',
                'motorista',
                'motorista.filial',
                'motorista.cluster'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mapas carregados com sucesso.',
                'data'    => MapaResource::collection($mapas->get())
            ]);
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
                ->when($user->role === 'motorista', fn ($query) => $query->whereHas('mapa', function ($query) {
                    $query->where('data_entrega', '=', now()->toDateString());
                }))
                ->with([
                    // Usamos um array no 'cliente' para injetar o withCount e carregar as outras relações
                    'cliente' => function ($query) {
                        $query->withCount('notasFiscais') // Traz o número de notas sem carregar todos os registros
                            ->with(['filial', 'categoria', 'contatos']);
                    }
                ])->get();

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
}
