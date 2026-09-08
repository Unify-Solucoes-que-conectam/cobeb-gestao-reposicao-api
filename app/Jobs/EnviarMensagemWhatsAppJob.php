<?php

namespace App\Jobs;

use App\Exceptions\EvolutionException;
use App\Exceptions\WhatsAppNotConfiguredException;
use App\Jobs\Middleware\SpaceWhatsAppMessages;
use App\Models\AvariaWhatsAppNotification;
use App\Models\ClienteTelefones;
use App\Models\WhatsAppConfiguration;
use App\Support\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
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
        private array $notificationIds = [],
        private ?string $contactToPromoteId = null,
    ) {
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
        $this->markProcessing();

        try {
            if ($this->type === 'text') {
                $service->sendMessage($this->filialId, $this->phone, $this->message, $this->event, $this->variables);
                $this->markAccepted();

                return;
            }

            if ($this->type === 'media' && $this->storagePath && $this->fileName) {
                $service->sendMedia($this->filialId, $this->phone, $this->message, $this->storagePath, $this->fileName, $this->event ?? 'import_report', $this->variables);
                Storage::delete($this->storagePath);
                $this->markAccepted();

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
        catch (EvolutionException $exception) {
            if ($this->isRecipientInvalid($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
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

        if ($this->notificationIds !== []) {
            $recipientInvalid = $exception instanceof EvolutionException && $this->isRecipientInvalid($exception);
            AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
                'status' => $recipientInvalid
                    ? AvariaWhatsAppNotification::STATUS_REQUIRES_PHONE
                    : AvariaWhatsAppNotification::STATUS_FAILED,
                'error_code' => $recipientInvalid
                    ? 'WHATSAPP_NUMBER_NOT_FOUND'
                    : ($exception instanceof EvolutionException ? $exception->errorCode : 'WHATSAPP_SEND_FAILED'),
                'error_message' => $recipientInvalid
                    ? 'O número não está disponível para receber mensagens no WhatsApp.'
                    : 'Não foi possível enviar a notificação pelo WhatsApp.',
                'last_attempt_at' => now(),
            ]);
        }
    }

    private function markProcessing(): void
    {
        if ($this->notificationIds === []) {
            return;
        }

        AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
            'status' => AvariaWhatsAppNotification::STATUS_PROCESSING,
            'phone' => $this->phone,
            'attempts' => DB::raw('attempts + 1'),
            'last_attempt_at' => now(),
        ]);
    }

    private function markAccepted(): void
    {
        if ($this->contactToPromoteId) {
            $contact = ClienteTelefones::find($this->contactToPromoteId);

            if ($contact) {
                DB::transaction(function () use ($contact) {
                    $contact->cliente->contatos()->whereKeyNot($contact->getKey())->update(['isWhatsapp' => false]);
                    $contact->forceFill([
                        'isWhatsapp' => true,
                        'whatsapp_validation_status' => 'valid',
                        'whatsapp_verified_at' => now(),
                        'whatsapp_validation_provider' => WhatsAppConfiguration::PROVIDER_OFFICIAL,
                    ])->save();
                });
            }
        }

        if ($this->notificationIds !== []) {
            AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
                'status' => AvariaWhatsAppNotification::STATUS_ACCEPTED,
                'error_code' => null,
                'error_message' => null,
                'accepted_at' => now(),
                'last_attempt_at' => now(),
            ]);
        }
    }

    private function isRecipientInvalid(EvolutionException $exception): bool
    {
        $message = mb_strtolower((string) $exception->upstreamMessage);

        return str_contains($message, 'not registered')
            || str_contains($message, 'not exist')
            || str_contains($message, 'invalid number')
            || str_contains($message, 'não existe');
    }
}
