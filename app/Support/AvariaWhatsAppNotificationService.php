<?php

namespace App\Support;

use App\Exceptions\WhatsAppNumberNotFoundException;
use App\Jobs\PrepareAvariaWhatsAppNotificationJob;
use App\Models\Avaria;
use App\Models\AvariaWhatsAppNotification;
use App\Models\WhatsAppConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AvariaWhatsAppNotificationService
{
    public function __construct(private readonly WhatsAppRecipientResolver $recipients) {}

    public function queueForCurrentStatus(Avaria $avaria, ?string $correctedBy = null): ?AvariaWhatsAppNotification
    {
        $event = $this->eventForStatus($avaria->status);

        if (!$event) {
            return null;
        }

        $avarias = $event === AvariaWhatsAppNotification::EVENT_REPORT
            ? Avaria::query()
                ->where('cliente_id', $avaria->cliente_id)
                ->whereDate('data_emissao', $avaria->data_emissao)
                ->get()
            : collect([$avaria]);
        $correlationId = (string) Str::uuid();

        $notifications = DB::transaction(function () use ($avarias, $event, $correlationId, $correctedBy) {
            return $avarias->map(fn(Avaria $item) => AvariaWhatsAppNotification::updateOrCreate(
                ['avaria_id' => $item->id, 'event' => $event],
                [
                    'correlation_id' => $correlationId,
                    'status' => AvariaWhatsAppNotification::STATUS_QUEUED,
                    'phone' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'corrected_by' => $correctedBy,
                    'queued_at' => now(),
                    'accepted_at' => null,
                ],
            ));
        });

        PrepareAvariaWhatsAppNotificationJob::dispatch($notifications->pluck('id')->all());

        return $notifications->first();
    }

    public function validateContactAndRetry(Avaria $avaria, string $phone, string $userId): AvariaWhatsAppNotification
    {
        $event = $this->eventForStatus($avaria->status);

        if (!$event) {
            throw new RuntimeException('O estado atual da avaria não possui notificação de WhatsApp.');
        }

        $pending = AvariaWhatsAppNotification::query()
            ->where('avaria_id', $avaria->id)
            ->where('event', $event)
            ->first()
        ;

        if (!$pending || $pending->status === AvariaWhatsAppNotification::STATUS_ACCEPTED) {
            throw new RuntimeException('WHATSAPP_NOTIFICATION_NOT_PENDING');
        }

        if (in_array($pending->status, [
            AvariaWhatsAppNotification::STATUS_QUEUED,
            AvariaWhatsAppNotification::STATUS_PROCESSING,
        ], true)) {
            throw new RuntimeException('WHATSAPP_ALREADY_QUEUED');
        }

        $filialId = (string) $avaria->motorista?->filial_id;
        $result = DB::transaction(function () use ($avaria, $event, $userId, $filialId, $phone) {
            $existing = AvariaWhatsAppNotification::query()
                ->where('avaria_id', $avaria->id)
                ->where('event', $event)
                ->first()
            ;

            $group = $existing && $event === AvariaWhatsAppNotification::EVENT_REPORT
                ? AvariaWhatsAppNotification::query()
                    ->where('correlation_id', $existing->correlation_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : AvariaWhatsAppNotification::query()
                    ->whereKey($existing?->id)
                    ->lockForUpdate()
                    ->get()
            ;

            if ($group->isEmpty() || $group->contains(
                fn(AvariaWhatsAppNotification $item) => $item->status === AvariaWhatsAppNotification::STATUS_ACCEPTED,
            )) {
                throw new RuntimeException('WHATSAPP_NOTIFICATION_NOT_PENDING');
            }

            if ($group->contains(fn(AvariaWhatsAppNotification $item) => in_array($item->status, [
                AvariaWhatsAppNotification::STATUS_QUEUED,
                AvariaWhatsAppNotification::STATUS_PROCESSING,
            ], true))) {
                throw new RuntimeException('WHATSAPP_ALREADY_QUEUED');
            }

            $validated = $this->recipients->validate($filialId, $phone);

            if (!$validated['exists']) {
                throw new WhatsAppNumberNotFoundException();
            }

            $national = $validated['phone'];
            $configuration = $validated['configuration'];

            if ($configuration->provider === WhatsAppConfiguration::PROVIDER_BAILEYS) {
                $contact = $this->recipients->promote($avaria->cliente, $national, $configuration->provider);
            }
            else {
                $contact = $avaria->cliente->contatos()->updateOrCreate(
                    ['numero' => $national],
                    [
                        'isWhatsapp' => false,
                        'whatsapp_validation_status' => 'unknown',
                        'whatsapp_verified_at' => null,
                        'whatsapp_validation_provider' => WhatsAppConfiguration::PROVIDER_OFFICIAL,
                    ],
                );
            }

            AvariaWhatsAppNotification::whereIn('id', $group->pluck('id'))->update([
                'status' => AvariaWhatsAppNotification::STATUS_QUEUED,
                'phone' => $national,
                'error_code' => null,
                'error_message' => null,
                'corrected_by' => $userId,
                'queued_at' => now(),
                'accepted_at' => null,
            ]);

            return compact('group', 'national', 'configuration', 'contact');
        });

        PrepareAvariaWhatsAppNotificationJob::dispatch(
            $result['group']->pluck('id')->all(),
            $result['national'],
            $result['configuration']->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL
                ? $result['contact']->id
                : null,
        );

        return $result['group']->firstWhere('avaria_id', $avaria->id)->fresh();
    }

    public function eventForStatus(string $status): ?string
    {
        return match ($status) {
            'aguardando_aprovacao' => AvariaWhatsAppNotification::EVENT_REPORT,
            'aprovada' => AvariaWhatsAppNotification::EVENT_APPROVED,
            'reprovada' => AvariaWhatsAppNotification::EVENT_REJECTED,
            default => null,
        };
    }
}
