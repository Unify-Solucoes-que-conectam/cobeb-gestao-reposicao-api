<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\WhatsAppService;

class ProcessarRelatorioAvariaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $avarias;
    protected $cliente;
    protected $contatoCliente;
    protected $protocolo;

    public function __construct($avarias, $cliente, $contatoCliente, $protocolo)
    {
        $this->avarias = $avarias;
        $this->cliente = $cliente;
        $this->contatoCliente = $contatoCliente;
        $this->protocolo = $protocolo;
    }

    public function handle()
    {
        $data = [
            'protocolo' => $this->protocolo,
            'cliente'   => $this->cliente,
            'avarias'   => $this->avarias
        ];

        // 1. Gera o PDF
        $pdf = Pdf::loadView('pdf.avarias', $data);
        $nomeArquivo = 'relatorio_' . time() . '.pdf';
        $caminho = 'documentos/' . $nomeArquivo;

        // 2. Salva no Storage
        Storage::disk('public')->put($caminho, $pdf->output());

        // 3. Define a saudação
        $horario = now()->format('H');
        if ($horario >= 5 && $horario < 12) {
            $saudacao = 'Bom dia';
        } elseif ($horario >= 12 && $horario < 18) {
            $saudacao = 'Boa tarde';
        } else {
            $saudacao = 'Boa noite';
        }

        // 4. Envia via WhatsApp
        if ($this->contatoCliente) {
            $whatsapp = new WhatsAppService();
            $whatsapp->sendMedia(
                $this->contatoCliente->numero,
                'document',
                'application/pdf',
                $saudacao . ' ' . $this->cliente->nome . ', foram encontradas algumas avarias na entrega de hoje, segue relação com mais detalhes 📃.' . "\n" . 'Logo você receberá uma mensagem de aprovação!',
                base64_encode($pdf->output()),
                $nomeArquivo
            );
        }
    }
}
