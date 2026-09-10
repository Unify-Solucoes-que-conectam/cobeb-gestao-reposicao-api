<?php

namespace App\Services;

use App\Jobs\EnviarMensagemWhatsAppJob;
use App\Models\Avaria;

class AvariaWhatsAppNotificationService
{
    public function queue(Avaria $avaria, string $phone, bool $isRetry = false): void
    {
        $avaria->loadMissing(['cliente', 'motorista.filial']);
        if (!in_array($avaria->status, ['aprovada', 'reprovada'], true)) {
            throw new \RuntimeException('Somente avarias aprovadas ou reprovadas possuem esta notificação.');
        }

        $approved = $avaria->status === 'aprovada';
        $message = $approved
            ? "Prezado(a) *{$avaria->cliente->razao_social}*,\n\nInformamos que a solicitação de troca referente ao protocolo #*AVR-{$avaria->id}* foi *aprovada* pela nossa equipe.\n\nO processo de substituição dos produtos avariados já está em andamento. Em caso de dúvidas, por gentileza, entre em contato conosco.\n\nAtenciosamente,\n*{$avaria->motorista->filial->descricao}*"
            : "Prezado(a) *{$avaria->cliente->razao_social}*,\n\nInformamos que a solicitação de troca referente ao protocolo #*AVR-{$avaria->id}* foi *reprovada* pela nossa equipe.\n\n*Motivo:* _{$avaria->motivo_reprovacao}_\n\nEm caso de dúvidas, por gentileza, entre em contato conosco.\n\nAtenciosamente,\n*{$avaria->motorista->filial->descricao}*";
        $event = $approved ? 'avaria_approved' : 'avaria_rejected';
        $variables = $approved
            ? [$avaria->cliente->razao_social, "AVR-{$avaria->id}", $avaria->motorista->filial->descricao]
            : [$avaria->cliente->razao_social, "AVR-{$avaria->id}", $avaria->motivo_reprovacao, $avaria->motorista->filial->descricao];

        $avaria->update([
            'whatsapp_notification_status' => 'pending',
            'whatsapp_notification_phone' => $phone,
            'whatsapp_notification_error' => null,
            'whatsapp_notification_sent_at' => null,
        ]);

        EnviarMensagemWhatsAppJob::dispatch(
            $avaria->motorista->filial_id,
            $phone,
            'text',
            $message,
            null,
            null,
            $event,
            $variables,
            $avaria->id,
            $isRetry,
        )->afterCommit();
    }
}
