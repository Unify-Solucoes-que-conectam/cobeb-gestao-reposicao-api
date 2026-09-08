<?php

namespace App\Jobs;

use App\Exceptions\EvolutionException;
use App\Exceptions\WhatsAppNotConfiguredException;
use App\Models\Avaria;
use App\Models\AvariaWhatsAppNotification;
use App\Support\WhatsAppRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PrepareAvariaWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private array $notificationIds,
        private ?string $preferredPhone = null,
        private ?string $contactToPromoteId = null,
    ) {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppRecipientResolver $recipients): void
    {
        $notifications = AvariaWhatsAppNotification::query()
            ->whereIn('id', $this->notificationIds)
            ->get()
        ;
        $notification = $notifications->first();

        if (!$notification) {
            return;
        }

        $avaria = Avaria::with([
            'cliente.contatos',
            'motorista.filial',
            'itens.produtoNotaFiscal.notaFiscal',
            'itens.tipoAvaria',
        ])->find($notification->avaria_id);

        if (!$avaria || !$avaria->cliente || !$avaria->motorista?->filial_id) {
            $this->markFailed('WHATSAPP_NOTIFICATION_CONTEXT_INVALID', 'Os dados da avaria estão incompletos.');

            return;
        }

        try {
            $resolved = $this->preferredPhone
                ? ['phone' => $this->preferredPhone]
                : $recipients->resolve($avaria->cliente, $avaria->motorista->filial_id);
            $phone = $resolved['phone'] ?? null;

            if (!$phone) {
                $this->markRequiresPhone('WHATSAPP_NUMBER_NOT_FOUND', 'Nenhum contato cadastrado está registrado no WhatsApp.');

                return;
            }

            AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
                'status' => AvariaWhatsAppNotification::STATUS_PROCESSING,
                'phone' => $phone,
                'error_code' => null,
                'error_message' => null,
                'last_attempt_at' => now(),
            ]);

            if ($notification->event === AvariaWhatsAppNotification::EVENT_REPORT) {
                $this->prepareReport($avaria, $phone);

                return;
            }

            $this->prepareStatusMessage($avaria, $notification->event, $phone);
        }
        catch (WhatsAppNotConfiguredException $exception) {
            $code = str_contains($exception->getMessage(), 'conectado')
                ? 'WHATSAPP_DISCONNECTED'
                : 'WHATSAPP_NOT_CONFIGURED';
            $this->markFailed($code, $exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $code = $exception instanceof EvolutionException ? $exception->errorCode : 'WHATSAPP_PREPARATION_FAILED';
        $this->markFailed($code, 'Não foi possível preparar a notificação de WhatsApp.');
        Log::error('Falha ao preparar notificação de avaria para WhatsApp.', [
            'notification_ids' => $this->notificationIds,
            'error_code' => $code,
            'error' => $exception->getMessage(),
        ]);
    }

    private function prepareReport(Avaria $avaria, string $phone): void
    {
        $avarias = Avaria::with(['itens.produtoNotaFiscal.notaFiscal', 'itens.tipoAvaria'])
            ->where('cliente_id', $avaria->cliente_id)
            ->whereDate('data_emissao', $avaria->data_emissao)
            ->get()
        ;
        $clienteModel = $avaria->cliente;
        $endereco = implode(', ', array_filter([
            $clienteModel->endereco,
            $clienteModel->bairro,
            trim(($clienteModel->cidade ?? '') . ' - ' . ($clienteModel->uf ?? ''), ' -'),
            $clienteModel->cep ? 'CEP: ' . $clienteModel->cep : null,
        ]));
        $tipoDocumento = Str::length($clienteModel->documento ?? '') === 11 ? 'cpf' : 'cnpj';
        $cliente = (object) [
            'nome' => $clienteModel->razao_social ?? $clienteModel->nome_fantasia ?? 'Cliente',
            $tipoDocumento => $clienteModel->documento ?? '',
            'endereco' => $endereco ?: 'Endereço não cadastrado',
            'telefone' => $phone,
        ];

        ProcessarRelatorioAvariaJob::dispatch(
            $avarias,
            $cliente,
            $phone,
            'AVR-' . $avaria->id,
            $avaria->motorista->filial_id,
            null,
            $this->notificationIds,
            $this->contactToPromoteId,
        )->onQueue('whatsapp');
    }

    private function prepareStatusMessage(Avaria $avaria, string $event, string $phone): void
    {
        $approved = $event === AvariaWhatsAppNotification::EVENT_APPROVED;
        $message = $approved
            ? "Prezado(a) *{$avaria->cliente->razao_social}*,\n\nInformamos que a solicitação de troca referente ao protocolo #*AVR-{$avaria->id}* foi *aprovada* pela nossa equipe.\n\nO processo de substituição dos produtos avariados já está em andamento. Em caso de dúvidas, por gentileza, entre em contato conosco.\n\nAtenciosamente,\n*{$avaria->motorista->filial->descricao}*"
            : "Prezado(a) *{$avaria->cliente->razao_social}*,\n\nInformamos que a solicitação de troca referente ao protocolo #*AVR-{$avaria->id}* foi *reprovada* pela nossa equipe.\n\n*Motivo:* _{$avaria->motivo_reprovacao}_\n\nEm caso de dúvidas, por gentileza, entre em contato conosco.\n\nAtenciosamente,\n*{$avaria->motorista->filial->descricao}*";
        $variables = $approved
            ? [$avaria->cliente->razao_social, "AVR-{$avaria->id}", $avaria->motorista->filial->descricao]
            : [$avaria->cliente->razao_social, "AVR-{$avaria->id}", $avaria->motivo_reprovacao, $avaria->motorista->filial->descricao];

        EnviarMensagemWhatsAppJob::dispatch(
            $avaria->motorista->filial_id,
            $phone,
            'text',
            $message,
            null,
            null,
            $event,
            $variables,
            $this->notificationIds,
            $this->contactToPromoteId,
        );
    }

    private function markRequiresPhone(string $code, string $message): void
    {
        AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
            'status' => AvariaWhatsAppNotification::STATUS_REQUIRES_PHONE,
            'error_code' => $code,
            'error_message' => $message,
            'last_attempt_at' => now(),
        ]);
    }

    private function markFailed(string $code, string $message): void
    {
        AvariaWhatsAppNotification::whereIn('id', $this->notificationIds)->update([
            'status' => AvariaWhatsAppNotification::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'last_attempt_at' => now(),
        ]);
    }
}
