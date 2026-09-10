<?php

namespace App\Jobs;

use App\Exceptions\WhatsAppNotConfiguredException;
use App\Jobs\Middleware\SpaceWhatsAppMessages;
use App\Models\WhatsAppConfiguration;
use App\Models\Avaria;
use App\Models\ClienteTelefones;
use App\Exceptions\EvolutionException;
use App\Events\GlobalEvent;
use App\Support\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EnviarMensagemWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    private ?string $avariaId = null;

    private bool $isAvariaRetry = false;

    public function __construct(
        private string $filialId,
        private string $phone,
        private string $type,
        private string $message,
        private ?string $storagePath = null,
        private ?string $fileName = null,
        private ?string $event = null,
        private array $variables = [],
        ?string $avariaId = null,
        bool $isAvariaRetry = false,
    ) {
        $this->avariaId = $avariaId;
        $this->isAvariaRetry = $isAvariaRetry;
        $this->onQueue('whatsapp');
    }

    public function filialId(): string
    {
        return $this->filialId;
    }

    public function rateLimitKey(): string
    {
        $configuration = WhatsAppConfiguration::resolveForFilial($this->filialId);

        return $configuration
            ? 'configuration:' . $configuration->getKey()
            : 'filial:' . $this->filialId;
    }

    public function middleware(): array
    {
        return [new SpaceWhatsAppMessages()];
    }

    public function handle(WhatsAppService $service): void
    {
        try {
            if ($this->type === 'text') {
                $service->sendMessage($this->filialId, $this->phone, $this->message, $this->event, $this->variables);

                $this->markAvariaAsSent();

                return;
            }

            if ($this->type === 'media' && $this->storagePath && $this->fileName) {
                $service->sendMedia($this->filialId, $this->phone, $this->message, $this->storagePath, $this->fileName, $this->event ?? 'import_report', $this->variables);
                Storage::delete($this->storagePath);
                $this->markAvariaAsSent();

                return;
            }

            throw new \InvalidArgumentException('Tipo de mensagem WhatsApp inválido.');
        }
        catch (EvolutionException $exception) {
            if ($exception->errorCode === 'WHATSAPP_NUMBER_NOT_FOUND') {
                $this->fail($exception);

                return;
            }

            throw $exception;
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

        if (!$this->avariaId) {
            return;
        }

        $avaria = Avaria::query()->find($this->avariaId);
        if (!$avaria) {
            return;
        }

        $numberNotFound = $exception instanceof EvolutionException
            && $exception->errorCode === 'WHATSAPP_NUMBER_NOT_FOUND';
        $message = $numberNotFound
            ? 'O número informado não foi encontrado no WhatsApp. Informe um número válido e reenvie a notificação.'
            : 'Não foi possível enviar a notificação pelo WhatsApp. Confira o número e tente novamente.';

        $avaria->update([
            'whatsapp_notification_status' => 'failed',
            'whatsapp_notification_error' => $message,
        ]);

        if ($numberNotFound) {
            ClienteTelefones::query()
                ->where('cliente_id', $avaria->cliente_id)
                ->where('numero', preg_replace('/\D/', '', $this->phone))
                ->update(['isWhatsapp' => false]);
        }

        event(new GlobalEvent([
            'titulo' => "Falha no WhatsApp — Avaria #{$avaria->id}",
            'mensagem' => "Não foi possível notificar o cliente da avaria #{$avaria->id}. {$message}",
            'tipo' => 'error',
            'link' => "/admin/avarias?avaria={$avaria->id}",
        ]));
    }

    private function markAvariaAsSent(): void
    {
        if (!$this->avariaId) {
            return;
        }

        Avaria::query()->whereKey($this->avariaId)->update([
            'whatsapp_notification_status' => 'sent',
            'whatsapp_notification_error' => null,
            'whatsapp_notification_sent_at' => now(),
        ]);

        if ($this->isAvariaRetry) {
            event(new GlobalEvent([
                'titulo' => "WhatsApp reenviado — Avaria #{$this->avariaId}",
                'mensagem' => "O reenvio da notificação da avaria #{$this->avariaId} para o novo número foi concluído com sucesso.",
                'tipo' => 'success',
                'link' => "/admin/avarias?avaria={$this->avariaId}",
            ]));
        }
    }
}
