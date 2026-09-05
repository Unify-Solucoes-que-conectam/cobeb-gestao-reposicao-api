<?php

namespace App\Console\Commands;

use App\Exceptions\EvolutionException;
use App\Models\WhatsAppConfiguration;
use App\Support\EvolutionClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWhatsAppConnections extends Command
{
    protected $signature = 'whatsapp:sync-connections';

    protected $description = 'Atualiza o estado das conexões WhatsApp por filial';

    public function handle(EvolutionClient $evolution): int
    {
        WhatsAppConfiguration::query()->eachById(function (WhatsAppConfiguration $configuration) use ($evolution) {
            try {
                $response = $evolution->connectionState($configuration);
                $state = data_get($response, 'instance.state') ?? data_get($response, 'state') ?? 'disconnected';
                $status = match ($state) {
                    'open', 'connected' => 'connected',
                    'connecting' => $configuration->provider === WhatsAppConfiguration::PROVIDER_BAILEYS ? 'waiting_qr' : 'pending_webhook',
                    default => 'disconnected',
                };

                $configuration->update([
                    'status' => $status,
                    'connected_phone' => $this->connectedPhoneFrom($response) ?? $configuration->connected_phone,
                    'connected_at' => $status === 'connected' ? ($configuration->connected_at ?? now()) : $configuration->connected_at,
                    'last_checked_at' => now(),
                    'last_error' => null,
                ]);

                if ($configuration->token_expires_at?->isBefore(now()->addDays(14))) {
                    Log::warning('Token oficial do WhatsApp próximo da expiração.', ['filial_id' => $configuration->filial_id]);
                }
            }
            catch (Throwable $exception) {
                $configuration->update([
                    'status' => 'error',
                    'last_checked_at' => now(),
                    'last_error' => $exception instanceof EvolutionException
                        ? $exception->getMessage()
                        : 'Evolution indisponível.',
                ]);
            }
        });

        $this->checkOrphanInstances($evolution);

        return self::SUCCESS;
    }

    private function checkOrphanInstances(EvolutionClient $evolution): void
    {
        try {
            $known = WhatsAppConfiguration::query()->pluck('instance_name')->all();
            $instances = $evolution->instances();

            foreach ($instances as $instance) {
                $name = data_get($instance, 'name')
                    ?? data_get($instance, 'instanceName')
                    ?? data_get($instance, 'instance.instanceName');

                if (!is_string($name) || !str_starts_with($name, 'cobeb-') || in_array($name, $known, true)) {
                    continue;
                }

                $cacheKey = 'whatsapp-orphan:' . sha1($name);

                if (Cache::add($cacheKey, true, now()->addHour())) {
                    Log::warning('Instância órfã encontrada na Evolution.', ['instance_name' => $name]);
                }
            }
        }
        catch (Throwable $exception) {
            Log::warning('Não foi possível verificar instâncias órfãs da Evolution.', [
                'error' => $exception instanceof EvolutionException ? $exception->getMessage() : 'Evolution indisponível.',
            ]);
        }
    }

    private function connectedPhoneFrom(array $response): ?string
    {
        $value = data_get($response, 'instance.ownerJid')
            ?? data_get($response, 'instance.owner')
            ?? data_get($response, 'instance.number')
            ?? data_get($response, 'ownerJid');

        if (!is_string($value) || $value === '') {
            return null;
        }

        return preg_replace('/\D/', '', explode('@', $value)[0]) ?: null;
    }
}
