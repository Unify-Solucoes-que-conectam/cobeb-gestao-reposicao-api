<?php

namespace App\Http\Controllers;

use App\Exports\ClientesExport;
use App\Exports\MapasExport;
use App\Exports\MotoristasExport;
use App\Exports\ProdutosExport;
use App\Exports\VendasTrocasExport;
use App\Support\Tenant;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    protected array $modelos = [
        'clientes' => ClientesExport::class,
        'produtos' => ProdutosExport::class,
        'motoristas' => MotoristasExport::class,
        'mapas' => MapasExport::class,
        'vendas_trocas' => VendasTrocasExport::class,
    ];

    public function exportarModelo(string $modelo)
    {
        if (!array_key_exists($modelo, $this->modelos)) {
            return response()->json(['error' => 'Modelo inválido.'], 400);
        }

        // o modelo de vendas e trocas lista clientes/notas: fora do "Geral", só os da filial conectada
        $export = $modelo === 'vendas_trocas'
            ? new VendasTrocasExport(Tenant::atual()->filial)
            : new $this->modelos[$modelo];

        return Excel::download($export, "{$modelo}.xlsx");
    }
}
