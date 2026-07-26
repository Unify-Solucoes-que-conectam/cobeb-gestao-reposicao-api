<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use App\Models\ImportBatch;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
        ], [
            'type.required' => 'Type is required.',
            'type.in' => 'Type is invalid.',
            'file.required' => 'File is required.',
            'file.mimes' => 'File must be CSV or Excel.',
            'file.max' => 'File size must not exceed 10MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Salva o arquivo no storage
        $path = $request->file('file')->store('imports');

        try {
            $batch = ImportBatch::query()->create([
                'user_id' => $user->id,
                'type' => $request->input('type'),
                'status' => 'pending',
                'total_rows' => 0,
                'processed_rows' => 0,
                'percentage' => 0,
                'last_log' => 'Queued',
                'current_step' => 'queued',
            ]);

            // Dispara o Job na fila 'imports'
            ProcessImportJob::dispatch($batch->id, $path, $batch->type)
                ->onQueue('imports');

            return response()->json([
                'success' => true,
                'data' => $batch,
            ]);
        } catch (Exception $e) {
            // Se der erro ao criar no banco ou na fila, remove o arquivo salvo
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
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $imports = ImportBatch::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'processing', 'failed'])
                    ->orWhere(function ($q) {
                        $q->where('status', 'completed')
                            ->where('updated_at', '>=', now()->subMinutes(5));
                    });
            })
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
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $batch = ImportBatch::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
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
