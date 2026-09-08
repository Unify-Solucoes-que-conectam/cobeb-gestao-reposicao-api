<?php

namespace App\Jobs;

use App\Models\AvariaWhatsAppNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessarRelatorioAvariaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $avarias;

    protected $cliente;

    protected $contatoCliente;

    protected $protocolo;

    protected $mensagem;

    protected string $filialId;

    protected array $notificationIds;

    protected ?string $contactToPromoteId;

    public function __construct(
        $avarias,
        $cliente,
        $contatoCliente,
        $protocolo,
        string $filialId,
        $mensagem = null,
        array $notificationIds = [],
        ?string $contactToPromoteId = null,
    )
    {
        $this->avarias = $avarias;
        $this->cliente = $cliente;
        $this->contatoCliente = $contatoCliente;
        $this->protocolo = $protocolo;
        $this->mensagem = $mensagem;
        $this->filialId = $filialId;
        $this->notificationIds = $notificationIds;
        $this->contactToPromoteId = $contactToPromoteId;
    }

    public function handle(): void
    {
        if (!$this->contatoCliente) {
            return;
        }

        $data = [
            'protocolo' => $this->protocolo,
            'cliente' => $this->cliente,
            'avarias' => $this->avarias,
        ];

        $pdf = Pdf::loadView('pdf.avarias', $data);
        $nomeArquivo = 'whatsapp-queue/relatorio_' . uniqid() . '.pdf';

        Storage::put($nomeArquivo, $pdf->output());

        $horario = (int) now()->format('H');

        if ($horario >= 5 && $horario < 12) {
            $saudacao = 'Bom dia';
        }
        elseif ($horario >= 12 && $horario < 18) {
            $saudacao = 'Boa tarde';
        }
        else {
            $saudacao = 'Boa noite';
        }

        $caption = $this->mensagem ?? $saudacao . ' *' . $this->cliente->nome . '*! ' . "\n\n"
            . 'Foram encontradas algumas avarias na entrega de hoje, segue relação com mais detalhes 📃.' . "\n\n"
            . 'Logo você receberá uma mensagem de aprovação!';

        $phone = $this->contatoCliente->numero ?? $this->contatoCliente;

        EnviarMensagemWhatsAppJob::dispatch(
            $this->filialId,
            $phone,
            'media',
            $caption,
            $nomeArquivo,
            basename($nomeArquivo),
            'import_report',
            [$this->cliente->nome, $this->protocolo],
            $this->notificationIds,
            $this->contactToPromoteId,
        );
    }

    public function failed(Throwable $exception): void
    {
        if ($this->notificationIds !== []) {
            AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
                'status' => AvariaWhatsAppNotification::STATUS_FAILED,
                'error_code' => 'WHATSAPP_REPORT_GENERATION_FAILED',
                'error_message' => 'Não foi possível gerar o relatório para envio.',
                'last_attempt_at' => now(),
            ]);
        }
    }
}
