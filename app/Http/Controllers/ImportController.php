<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use App\Models\ImportBatch;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    private const ALLOWED_TYPES = [
        'produtos',
        'clientes',
        'motoristas',
        'mapas',
        'vendas_trocas'
    ];

    public function start(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'type'      => ['required', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'records'   => ['required', 'array', 'min:1', 'max:5000'],
            'records.*' => ['required', 'array'],
        ], [
            'type.required'    => 'Type is required.',
            'type.in'          => 'Type is invalid.',
            'records.required' => 'Records are required.',
            'records.array'    => 'Records must be an array.',
            'records.min'      => 'At least one record is required.',
            'records.max'      => 'Maximum of 5000 records per import.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $records = $request->input('records');
        $path    = 'imports/' . Str::uuid() . '.json';
        Storage::put($path, json_encode($records));

        try {
            $batch = ImportBatch::query()->create([
                'type'           => $request->input('type'),
                'status'         => 'pending',
                'total_rows'     => count($records),
                'processed_rows' => 0,
                'percentage'     => 0,
                'last_log'       => 'Queued',
                'current_step'   => 'queued',
            ]);

            ProcessImportJob::dispatch($batch->id, $path, $batch->type)
                ->onQueue('imports');

            return response()->json([
                'success' => true,
                'data' => $batch,
            ]);
        } catch (Exception $e) {
            Storage::delete($path);

            return response()->json([
                'success' => false,
                'message' => 'Failed to enqueue import process: ' . $e->getMessage()
            ], 500);
        }
    }

    public function list(Request $request)
    {
        $user = $request->user();

        // Subquery para encontrar o maior ID (ou última data) para cada 'type'
        $latestIds = ImportBatch::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('type');

        // Query principal filtrando apenas os IDs obtidos na subquery
        $imports = ImportBatch::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', ['pending', 'processing', 'failed', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $imports,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuário desconectado.'], 401);
        }

        $batch = ImportBatch::query()
            ->where('id', $id)
            ->first();

        if (!$batch) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $batch,
        ]);
    }
}
