<?php

namespace App\Services;

use App\Jobs\EnviarMensagemWhatsAppJob;
use App\Jobs\ProcessarRelatorioAvariaJob;
use App\Models\Avaria;

class AvariaWhatsAppNotificationService
{
    public function queue(Avaria $avaria, string $phone, bool $isRetry = false): void
    {
        $avaria->loadMissing(['cliente.contatos', 'motorista.filial']);

        if (in_array($avaria->status, ['pendente', 'aguardando_aprovacao'], true)) {
            $this->queueRegistrationReport($avaria, $phone, $isRetry);

            return;
        }

        $approved = in_array($avaria->status, ['aprovada', 'trocada'], true);
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

    private function queueRegistrationReport(Avaria $avaria, string $phone, bool $isRetry): void
    {
        $avarias = Avaria::query()
            ->where('cliente_id', $avaria->cliente_id)
            ->where('data_emissao', $avaria->data_emissao)
            ->with([
                'itens.produtoNotaFiscal.notaFiscal',
                'itens.tipoAvaria',
                'cliente',
            ])
            ->get();

        $cliente = (object) [
            'nome' => $avaria->cliente->razao_social ?? $avaria->cliente->nome_fantasia ?? 'Cliente',
            (strlen((string) $avaria->cliente->documento) === 11 ? 'cpf' : 'cnpj') => $avaria->cliente->documento ?? '',
            'endereco' => implode(', ', array_filter([
                $avaria->cliente->endereco,
                $avaria->cliente->bairro,
                trim(($avaria->cliente->cidade ?? '') . ' - ' . ($avaria->cliente->uf ?? ''), ' -'),
                $avaria->cliente->cep ? 'CEP: ' . $avaria->cliente->cep : null,
            ])),
            'telefone' => $phone,
        ];

        $avaria->update([
            'whatsapp_notification_status' => 'pending',
            'whatsapp_notification_phone' => $phone,
            'whatsapp_notification_error' => null,
            'whatsapp_notification_sent_at' => null,
        ]);

        ProcessarRelatorioAvariaJob::dispatch(
            $avarias,
            $cliente,
            $phone,
            'AVR-' . $avaria->id,
            $avaria->motorista->filial_id,
            null,
            $avaria->id,
            $isRetry,
        )->afterCommit();
    }
}
