<?php

namespace App\Jobs;

use App\Events\ImportProgressUpdated;
use App\Imports\CountRowsImport;
use App\Imports\GenericImport;
use App\Models\ImportBatch;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $batchId;
    private string $path;
    private string $type;
    
    public $timeout = 600;

    public function __construct(string $batchId, string $path, string $type)
    {
        $this->batchId = $batchId;
        $this->path = $path;
        $this->type = $type;
    }

    public function handle(): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if (!$batch) {
            return;
        }

        $batch->update([
            'status' => 'processing',
            'current_step' => 'counting',
            'last_log' => 'Counting rows',
        ]);
        event(new ImportProgressUpdated($batch));

        $fullPath = Storage::path($this->path);

        try {
            $countImport = new CountRowsImport();
            Excel::import($countImport, $fullPath);

            $totalRows = $countImport->getRowCount();
            if ($totalRows === 0) {
                $batch->update([
                    'status' => 'failed',
                    'processed_rows' => 0,
                    'percentage' => 0,
                    'current_step' => 'empty',
                    'last_log' => 'No rows detected. Check headers and delimiter.',
                ]);
                event(new ImportProgressUpdated($batch->fresh()));
                return;
            }
            $batch->update([
                'total_rows' => $totalRows,
                'processed_rows' => 0,
                'percentage' => 0,
                'current_step' => 'importing',
                'last_log' => 'Starting import',
            ]);
            event(new ImportProgressUpdated($batch->fresh()));

            $import = new GenericImport($this->batchId, $this->type, $totalRows);
            Excel::import($import, $fullPath);

            $errorCount = $import->getErrorCount();
            $successCount = $totalRows - $errorCount;

            if ($errorCount === $totalRows) {
                $batch->update([
                    'status' => 'failed',
                    'processed_rows' => $totalRows,
                    'percentage' => 100,
                    'current_step' => 'failed',
                    'last_log' => "All {$totalRows} rows failed. Check logs for details.",
                ]);
            } else {
                $batch->update([
                    'status' => 'completed',
                    'processed_rows' => $totalRows,
                    'percentage' => 100,
                    'current_step' => 'done',
                    'last_log' => $errorCount > 0
                        ? "Completed: {$successCount} imported, {$errorCount} errors"
                        : "Import completed — {$successCount} rows imported",
                ]);

                if ($this->type === 'vendas_trocas' && !empty($import->getTrocas())) {

                    $trocas = $import->getTrocas();

                    foreach ($trocas as $dadosRelatorio) {


                        $dtOperacao = $dadosRelatorio['data_operacao'] ?? now()->format('d/m/Y');
                        $cliente = $dadosRelatorio['cliente'] ?? null;
                        $avarias = $dadosRelatorio['avarias'] ?? [];

                        Log::info("Dados Relatório: " . json_encode($cliente));

                        // Dispara o job para processar o relatório de avarias
                        $horario = now()->format('H');
                        if ($horario >= 5 && $horario < 12) {
                            $saudacao = 'Bom dia';
                        } elseif ($horario >= 12 && $horario < 18) {
                            $saudacao = 'Boa tarde';
                        } else {
                            $saudacao = 'Boa noite';
                        }

                        $mensagem = "{$saudacao} *{$cliente->nome}*!\n\nSua troca referente às avarias registradas em " . Carbon::parse($dtOperacao)->format('d/m/Y') . " será enviada hoje!\n\nSegue relação dos itens com mais detalhes.";
                        $contatoCliente = $dadosRelatorio['contatoCliente'] ?? '';
                        $protocolo = $dadosRelatorio['protocolo'] ?? null;

                        ProcessarRelatorioAvariaJob::dispatch($avarias, $cliente, $contatoCliente, $protocolo, $mensagem)
                            ->onQueue('imports');
                    }
                }
            }
            event(new ImportProgressUpdated($batch->fresh()));
        } catch (\Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'current_step' => 'failed',
                'last_log' => 'Import failed: ' . $exception->getMessage(),
            ]);
            event(new ImportProgressUpdated($batch->fresh()));
        }
    }
}
