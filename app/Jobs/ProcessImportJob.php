<?php

namespace App\Jobs;

use App\Events\ImportProgressUpdated;
use App\Imports\GenericImport;
use App\Models\ImportBatch;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $batchId;

    private string $path;

    private string $type;

    private array $options = [];
    private ?string $userId = null;

    public $timeout = 600;

    public function __construct(string $batchId, string $path, string $type, array $options = [], ?string $userId = null)
    {
        $this->batchId = $batchId;
        $this->path = $path;
        $this->type = $type;
        $this->options = $options;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if (!$batch) {
            return;
        }

        $batch->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'percentage' => 0,
            'current_step' => 'importing',
            'last_log' => 'Starting import',
        ]);
        event(new ImportProgressUpdated($batch));

        $fullPath = Storage::path($this->path);
        $records = json_decode(file_get_contents($fullPath), true) ?? [];
        $totalRows = count($records);

        try {
            if ($totalRows === 0) {
                $batch->update([
                    'status' => 'failed',
                    'current_step' => 'empty',
                    'last_log' => 'No records to import.',
                ]);
                event(new ImportProgressUpdated($batch->fresh()));

                return;
            }

            $import = new GenericImport($this->batchId, $this->type, $totalRows, $this->options, $this->userId);
            $import->processRecords($records);

            $errorCount = $import->getErrorCount();
            $ignoredCount = $import->getIgnoredCount();
            $successCount = $totalRows - $errorCount - $ignoredCount;
            $batch->update(['row_errors' => $import->getRowErrors()]);

            if ($errorCount === $totalRows) {
                $batch->update([
                    'status' => 'failed',
                    'processed_rows' => $totalRows,
                    'percentage' => 100,
                    'current_step' => 'failed',
                    'last_log' => "All {$totalRows} rows failed. Check logs for details.",
                ]);
            }
            else {
                $batch->update([
                    'status' => 'completed',
                    'processed_rows' => $totalRows,
                    'percentage' => 100,
                    'current_step' => 'done',
                    'last_log' => "Concluído: {$successCount} importados, {$ignoredCount} ignorados, {$errorCount} erros.",
                ]);

                if ($this->type === 'vendas_trocas' && !empty($import->getTrocas())) {
                    $trocas = $import->getTrocas();

                    foreach ($trocas as $dadosRelatorio) {
                        $dtOperacao = $dadosRelatorio['data_operacao'] ?? now()->format('d/m/Y');
                        $cliente = $dadosRelatorio['cliente'] ?? null;
                        $avarias = $dadosRelatorio['avarias'] ?? [];

                        // Dispara o job para processar o relatório de avarias
                        $horario = now()->format('H');

                        if ($horario >= 5 && $horario < 12) {
                            $saudacao = 'Bom dia';
                        }
                        elseif ($horario >= 12 && $horario < 18) {
                            $saudacao = 'Boa tarde';
                        }
                        else {
                            $saudacao = 'Boa noite';
                        }

                        $mensagem = "{$saudacao} *{$cliente->nome}*!\n\n" . implode("\n\n", $dadosRelatorio['avisos'] ?? []) . "\n\nSegue a relação dos itens registrados.";
                        $contatoCliente = $dadosRelatorio['contatoCliente'] ?? '';
                        $protocolo = $dadosRelatorio['protocolo'] ?? null;

                        ProcessarRelatorioAvariaJob::dispatch(
                            $avarias,
                            $cliente,
                            $contatoCliente,
                            $protocolo,
                            $dadosRelatorio['filial_id'],
                            $mensagem,
                        )
                            ->onQueue('imports')
                        ;
                    }
                }
            }
            event(new ImportProgressUpdated($batch->fresh()));
        }
        catch (\Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'current_step' => 'failed',
                'last_log' => 'Import failed: ' . $exception->getMessage(),
            ]);
            event(new ImportProgressUpdated($batch->fresh()));
        }
        finally {
            Storage::delete($this->path);
        }
    }
}
