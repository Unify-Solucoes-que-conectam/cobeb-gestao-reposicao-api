<?php

namespace App\Jobs;

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
    private string $phone,
    private string $type,
    private string $message,
    private ?string $storagePath = null,
    private ?string $fileName = null,
  ) {
    $this->onQueue('whatsapp');
  }

  public function middleware(): array
  {
    return [new RateLimited('whatsapp')];
  }

  public function handle(WhatsAppService $service): void
  {
    if ($this->type === 'text') {
      $sent = $service->sendMessage($this->phone, $this->message);

      if (!$sent) {
        throw new \RuntimeException("Falha ao enviar mensagem de texto para {$this->phone}");
      }

      return;
    }

    if ($this->type === 'media') {
      $content = Storage::get($this->storagePath);

      if ($content === null) {
        Log::error('Arquivo de mídia não encontrado para envio WhatsApp', ['path' => $this->storagePath]);
        throw new \RuntimeException("Arquivo não encontrado: {$this->storagePath}");
      }

      $sent = $service->sendMedia(
        $this->phone,
        'document',
        'application/pdf',
        $this->message,
        base64_encode($content),
        $this->fileName,
      );

      if (!$sent) {
        throw new \RuntimeException("Falha ao enviar mídia para {$this->phone}");
      }

      Storage::delete($this->storagePath);
    }
  }

  public function failed(Throwable $e): void
  {
    if ($this->type === 'media' && $this->storagePath) {
      Storage::delete($this->storagePath);
    }

    Log::error('EnviarMensagemWhatsAppJob falhou definitivamente', [
      'phone' => $this->phone,
      'type'  => $this->type,
      'error' => $e->getMessage(),
    ]);
  }
}
