<?php

namespace App\Http\Controllers;

use App\Exports\TrocasClientesExport;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class TrocasController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'operacao' => ['nullable', 'in:1,2,5,39'],
            'data_inicial' => ['nullable', 'date'],
            'data_final' => ['nullable', 'date', 'after_or_equal:data_inicial'],
        ]);
        try {
            $movimentos = $this->filteredMovements($validated)
                ->orderByDesc('data_operacao')->orderByDesc('created_at')
                ->paginate($validated['per_page'] ?? 20)->withQueryString();

            return response()->json(static::paginatedResponse($movimentos, [
                'message' => 'Movimentos consultados com sucesso',
            ]));
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Erro ao consultar trocas',
                'success' => false
            ], 500);
        }
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'data_inicial' => ['required', 'date'],
            'data_final' => ['required', 'date', 'after_or_equal:data_inicial'],
        ]);

        $rows = DB::table('trocas')
            ->join('produtos_nota_fiscal', 'produtos_nota_fiscal.id', '=', 'trocas.produto_nota_fiscal_id')
            ->join('notas_fiscais', 'notas_fiscais.id', '=', 'produtos_nota_fiscal.nota_fiscal_id')
            ->join('clientes', 'clientes.id', '=', 'notas_fiscais.cliente_id')
            ->whereBetween('trocas.data_operacao', [$validated['data_inicial'], $validated['data_final']])
            ->selectRaw("COALESCE(NULLIF(clientes.razao_social, ''), NULLIF(clientes.nome_fantasia, ''), clientes.codigo) as cliente")
            ->selectRaw('SUM(trocas.quantidade) as quantidade_trocada')
            ->groupBy('clientes.id', 'clientes.razao_social', 'clientes.nome_fantasia', 'clientes.codigo')
            ->orderBy('cliente')
            ->get();

        return Excel::download(new TrocasClientesExport($rows), "trocas_{$validated['data_inicial']}_{$validated['data_final']}.xlsx");
    }

    private function filteredMovements(array $filters)
    {
        $clientName = "COALESCE(NULLIF(clientes.razao_social, ''), NULLIF(clientes.nome_fantasia, ''), clientes.codigo)";
        $sales = DB::table('produtos_nota_fiscal')
            ->join('notas_fiscais', 'notas_fiscais.id', '=', 'produtos_nota_fiscal.nota_fiscal_id')
            ->join('clientes', 'clientes.id', '=', 'notas_fiscais.cliente_id')
            ->join('produtos', 'produtos.id', '=', 'produtos_nota_fiscal.produto_id')
            ->whereIn('produtos_nota_fiscal.operacao', ['1', '2'])
            ->selectRaw("produtos_nota_fiscal.id, 'nota' as origem, {$clientName} as cliente, clientes.codigo as cliente_codigo, notas_fiscais.numero as nota, produtos.codigo as produto_codigo, produtos.descricao as produto, produtos_nota_fiscal.quantidade, produtos_nota_fiscal.operacao, produtos_nota_fiscal.data_operacao, produtos_nota_fiscal.created_at");
        $trades = DB::table('trocas')
            ->join('produtos_nota_fiscal', 'produtos_nota_fiscal.id', '=', 'trocas.produto_nota_fiscal_id')
            ->join('notas_fiscais', 'notas_fiscais.id', '=', 'produtos_nota_fiscal.nota_fiscal_id')
            ->join('clientes', 'clientes.id', '=', 'notas_fiscais.cliente_id')
            ->join('produtos', 'produtos.id', '=', 'produtos_nota_fiscal.produto_id')
            ->whereIn('trocas.operacao', ['5', '39'])
            ->selectRaw("trocas.id, 'troca' as origem, {$clientName} as cliente, clientes.codigo as cliente_codigo, notas_fiscais.numero as nota, produtos.codigo as produto_codigo, produtos.descricao as produto, trocas.quantidade, trocas.operacao, trocas.data_operacao, trocas.created_at");

        $query = DB::query()->fromSub($sales->unionAll($trades), 'movimentos');
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('cliente', 'like', $search)->orWhere('cliente_codigo', 'like', $search)
                ->orWhere('nota', 'like', $search)->orWhere('produto_codigo', 'like', $search)->orWhere('produto', 'like', $search));
        }
        if (!empty($filters['operacao'])) $query->where('operacao', $filters['operacao']);
        if (!empty($filters['data_inicial'])) $query->whereDate('data_operacao', '>=', $filters['data_inicial']);
        if (!empty($filters['data_final'])) $query->whereDate('data_operacao', '<=', $filters['data_final']);
        return $query;
    }
}
