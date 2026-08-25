<?php

namespace App\Http\Controllers;

use App\Exports\ClientesExport;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    protected array $modelos = [
        'clientes' => ClientesExport::class,
    ];

    public function exportarModelo(string $modelo)
    {
        if (!array_key_exists($modelo, $this->modelos)) {
            return response()->json(['error' => 'Modelo inválido.'], 400);
        }

        return Excel::download(new $this->modelos[$modelo], "{$modelo}.xlsx");
    }
}
