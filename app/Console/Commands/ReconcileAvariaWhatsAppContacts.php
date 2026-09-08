<?php

namespace App\Console\Commands;

use App\Models\Avaria;
use App\Models\AvariaWhatsAppNotification;
use App\Models\ClienteTelefones;
use App\Models\WhatsAppConfiguration;
use App\Support\AvariaWhatsAppNotificationService;
use App\Support\BrazilianPhoneNumber;
use App\Support\EvolutionClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ReconcileAvariaWhatsAppContacts extends Command
{
    protected $signature = 'whatsapp:reconcile-avaria-contacts {--dry-run : Apenas mostra as alterações necessárias}';

    protected $description = 'Valida contatos das avarias ativas sem reenviar mensagens';

    public function handle(
        BrazilianPhoneNumber $phones,
        EvolutionClient $evolution,
        AvariaWhatsAppNotificationService $notifications,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $processedClients = [];
        $reportCorrelations = [];
        $summary = ['valid' => 0, 'invalid' => 0, 'unknown' => 0, 'errors' => 0];

        Avaria::with(['cliente.contatos', 'motorista'])
            ->whereIn('status', ['aguardando_aprovacao', 'aprovada', 'reprovada'])
            ->orderBy('id')
            ->chunk(100, function ($avarias) use (
                &$processedClients,
                &$reportCorrelations,
                &$summary,
                $dryRun,
                $phones,
                $evolution,
                $notifications,
            ) {
                foreach ($avarias as $avaria) {
                    $filialId = $avaria->motorista?->filial_id;
                    $cliente = $avaria->cliente;

                    if (!$cliente || !$filialId) {
                        $summary['errors']++;

                        continue;
                    }

                    $key = $cliente->id . ':' . $filialId;
                    $result = $processedClients[$key] ?? null;

                    if (!$result) {
                        $configuration = WhatsAppConfiguration::resolveForFilial($filialId);
                        $contacts = $cliente->contatos
                            ->mapWithKeys(function (ClienteTelefones $contact) use ($phones) {
                                $national = $phones->tryNational($contact->numero);

                                return $national ? [$phones->international($national) => $contact] : [];
                            })
                        ;

                        if (!$configuration || $configuration->status !== 'connected' || $contacts->isEmpty()) {
                            $result = ['status' => $contacts->isEmpty() ? 'invalid' : 'unknown', 'selected' => null];
                        }
                        elseif ($configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL) {
                            $result = ['status' => 'unknown', 'selected' => $contacts->first()];
                        }
                        else {
                            try {
                                $checked = $evolution->whatsappNumbers($configuration, $contacts->keys()->all());
                                $selected = $contacts->first(fn($contact, $number) => (bool) ($checked[$number] ?? false));
                                $result = ['status' => $selected ? 'valid' : 'invalid', 'selected' => $selected, 'checked' => $checked, 'contacts' => $contacts];
                            }
                            catch (Throwable $exception) {
                                $result = ['status' => 'unknown', 'selected' => null];
                                $summary['errors']++;
                                $this->warn("Cliente {$cliente->codigo}: não foi possível consultar a Evolution.");
                            }
                        }

                        $processedClients[$key] = $result;
                        $summary[$result['status']]++;

                        if (!$dryRun && isset($result['checked'])) {
                            foreach ($result['contacts'] as $international => $contact) {
                                $exists = (bool) ($result['checked'][$international] ?? false);
                                $contact->update([
                                    'isWhatsapp' => $result['selected']?->is($contact) ?? false,
                                    'whatsapp_validation_status' => $exists ? 'valid' : 'invalid',
                                    'whatsapp_verified_at' => now(),
                                    'whatsapp_validation_provider' => WhatsAppConfiguration::PROVIDER_BAILEYS,
                                ]);
                            }
                        }
                    }

                    $event = $notifications->eventForStatus($avaria->status);

                    if (!$dryRun && $event && !AvariaWhatsAppNotification::where([
                        'avaria_id' => $avaria->id,
                        'event' => $event,
                    ])->exists()) {
                        $correlationKey = $event === AvariaWhatsAppNotification::EVENT_REPORT
                            ? $cliente->id . ':' . Carbon::parse($avaria->data_emissao)->toDateString()
                            : (string) Str::uuid();

                        AvariaWhatsAppNotification::create([
                            'avaria_id' => $avaria->id,
                            'correlation_id' => $event === AvariaWhatsAppNotification::EVENT_REPORT
                                ? ($reportCorrelations[$correlationKey] ??= (string) Str::uuid())
                                : $correlationKey,
                            'event' => $event,
                            'status' => $result['status'] === 'invalid'
                                ? AvariaWhatsAppNotification::STATUS_REQUIRES_PHONE
                                : AvariaWhatsAppNotification::STATUS_UNKNOWN,
                            'phone' => $result['selected']?->numero,
                            'error_code' => $result['status'] === 'invalid' ? 'WHATSAPP_NUMBER_NOT_FOUND' : null,
                            'error_message' => $result['status'] === 'invalid'
                                ? 'Nenhum contato cadastrado está registrado no WhatsApp.'
                                : null,
                        ]);
                    }
                }
            })
        ;

        $this->table(['Resultado', 'Clientes'], collect($summary)->map(fn($count, $status) => [$status, $count]));
        $this->info($dryRun ? 'Simulação concluída; nenhum dado foi alterado.' : 'Reconciliação concluída; nenhuma mensagem foi enviada.');

        return self::SUCCESS;
    }
}
