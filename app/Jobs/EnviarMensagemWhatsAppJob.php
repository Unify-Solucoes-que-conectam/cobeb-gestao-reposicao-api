<?php

namespace App\Jobs;

use App\Exceptions\WhatsAppNotConfiguredException;
use App\Support\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EnviarMensagemWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private string $filialId,
        private string $phone,
        private string $type,
        private string $message,
        private ?string $storagePath = null,
        private ?string $fileName = null,
        private ?string $event = null,
        private array $variables = [],
    ) {
        $this->onQueue('whatsapp');
    }

    public function filialId(): string
    {
        return $this->filialId;
    }

    public function middleware(): array
    {
        return [(new RateLimited('whatsapp'))->releaseAfter(30)];
    }

    public function handle(WhatsAppService $service): void
    {
        try {
            if ($this->type === 'text') {
                $service->sendMessage($this->filialId, $this->phone, $this->message, $this->event, $this->variables);

                return;
            }

            if ($this->type === 'media' && $this->storagePath && $this->fileName) {
                $service->sendMedia($this->filialId, $this->phone, $this->message, $this->storagePath, $this->fileName, $this->event ?? 'import_report', $this->variables);
                Storage::delete($this->storagePath);

                return;
            }

            throw new \InvalidArgumentException('Tipo de mensagem WhatsApp inválido.');
        }
        catch (WhatsAppNotConfiguredException $exception) {
            Log::warning('Envio WhatsApp não executado por configuração da filial.', [
                'filial_id' => $this->filialId,
                'type' => $this->type,
                'reason' => $exception->getMessage(),
            ]);
            $this->fail($exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->type === 'media' && $this->storagePath) {
            Storage::delete($this->storagePath);
        }
        Log::error('EnviarMensagemWhatsAppJob falhou definitivamente.', [
            'filial_id' => $this->filialId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
