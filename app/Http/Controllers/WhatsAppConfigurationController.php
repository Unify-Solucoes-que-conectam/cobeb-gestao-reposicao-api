<?php

namespace App\Http\Controllers;

use App\Exceptions\EvolutionException;
use App\Jobs\EnviarMensagemWhatsAppJob;
use App\Models\Filial;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppTemplate;
use App\Support\EvolutionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class WhatsAppConfigurationController extends Controller
{
    public function __construct(private readonly EvolutionClient $evolution) {}

    public function index(): JsonResponse
    {
        $filiais = Filial::query()
            ->with(['whatsappConfiguration.templates'])
            ->orderBy('codigo')
            ->get()
            ->map(fn(Filial $filial) => [
                'filial' => ['id' => $filial->id, 'codigo' => $filial->codigo, 'descricao' => $filial->descricao],
                'configuration' => $this->serialize($filial->whatsappConfiguration),
            ])
        ;

        return $this->success($filiais, 'Configurações consultadas com sucesso.');
    }

    public function show(Filial $filial): JsonResponse
    {
        return $this->success($this->serialize($filial->whatsappConfiguration?->load('templates')));
    }

    public function official(Request $request, Filial $filial): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['nullable', 'string', 'min:20'],
            'phone_number_id' => ['required', 'string', 'max:100'],
            'business_account_id' => ['required', 'string', 'max:100'],
            'token_expires_at' => ['nullable', 'date', 'after:now'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        return $this->locked($filial, function () use ($request, $filial, $data) {
            $existing = $filial->whatsappConfiguration()->first();

            if ($existing && !$request->boolean('replace')) {
                return $this->error('Confirme a substituição da configuração atual.', 'REPLACEMENT_CONFIRMATION_REQUIRED', 409);
            }

            $token = $data['access_token'] ?? ($existing?->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL
                ? $existing->meta_access_token
                : null);

            if (!$token) {
                return $this->error('O token de acesso da Meta é obrigatório.', 'INVALID_PROVIDER_CREDENTIALS', 422);
            }

            $instanceName = $this->instanceName($filial);
            $created = false;

            try {
                $remote = $this->evolution->createOfficial(
                    $instanceName,
                    $token,
                    $data['phone_number_id'],
                    $data['business_account_id'],
                );
                $created = true;

                $configuration = DB::transaction(function () use ($request, $filial, $data, $token, $instanceName, $remote, $existing) {
                    $configuration = WhatsAppConfiguration::query()->updateOrCreate(
                        ['filial_id' => $filial->id],
                        [
                            'provider' => WhatsAppConfiguration::PROVIDER_OFFICIAL,
                            'instance_name' => $instanceName,
                            'instance_id' => data_get($remote, 'instance.instanceId') ?? data_get($remote, 'instance.instanceName'),
                            'instance_api_key' => is_string(data_get($remote, 'hash')) ? data_get($remote, 'hash') : null,
                            'meta_access_token' => $token,
                            'meta_phone_number_id' => $data['phone_number_id'],
                            'meta_business_account_id' => $data['business_account_id'],
                            'token_expires_at' => $data['token_expires_at'] ?? null,
                            'status' => 'pending_webhook',
                            'connected_phone' => null,
                            'last_error' => null,
                            'created_by' => $existing?->created_by ?? $request->user()->id,
                            'updated_by' => $request->user()->id,
                        ],
                    );
                    $configuration->templates()->delete();

                    return $configuration->load('templates');
                });

                $this->deletePreviousInstance($existing, $instanceName);
                $this->audit($request, $filial, 'official_configured');

                return $this->success([
                    'configuration' => $this->serialize($configuration),
                    'meta_webhook' => [
                        'url' => config('evolution.meta_webhook_url'),
                        'verify_token' => config('evolution.meta_webhook_token'),
                    ],
                ], 'Instância oficial criada. Configure agora o webhook e os templates.');
            }
            catch (EvolutionException $exception) {
                return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
            }
            catch (ConnectionException) {
                return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
            }
            catch (Throwable $exception) {
                if ($created) {
                    try {
                        $this->evolution->deleteInstance($instanceName);
                    }
                    catch (Throwable) {
                    }
                }
                report($exception);

                return $this->error('Não foi possível salvar a configuração.', 'CONFIGURATION_SAVE_FAILED', 500);
            }
        });
    }

    public function baileys(Request $request, Filial $filial): JsonResponse
    {
        $request->validate(['replace' => ['sometimes', 'boolean']]);

        return $this->locked($filial, function () use ($request, $filial) {
            $existing = $filial->whatsappConfiguration()->first();

            if ($existing && !$request->boolean('replace')) {
                return $this->error('Confirme a substituição da configuração atual.', 'REPLACEMENT_CONFIRMATION_REQUIRED', 409);
            }

            $instanceName = $this->instanceName($filial);

            try {
                $remote = $this->evolution->createBaileys($instanceName);
                $configuration = DB::transaction(function () use ($request, $filial, $instanceName, $remote, $existing) {
                    $configuration = WhatsAppConfiguration::query()->updateOrCreate(
                        ['filial_id' => $filial->id],
                        [
                            'provider' => WhatsAppConfiguration::PROVIDER_BAILEYS,
                            'instance_name' => $instanceName,
                            'instance_id' => data_get($remote, 'instance.instanceId') ?? data_get($remote, 'instance.instanceName'),
                            'instance_api_key' => is_string(data_get($remote, 'hash')) ? data_get($remote, 'hash') : null,
                            'meta_access_token' => null,
                            'meta_phone_number_id' => null,
                            'meta_business_account_id' => null,
                            'token_expires_at' => null,
                            'status' => 'waiting_qr',
                            'connected_phone' => null,
                            'last_error' => null,
                            'created_by' => $existing?->created_by ?? $request->user()->id,
                            'updated_by' => $request->user()->id,
                        ],
                    );
                    $configuration->templates()->delete();

                    return $configuration->load('templates');
                });

                $this->deletePreviousInstance($existing, $instanceName);
                $this->audit($request, $filial, 'baileys_configured');

                return $this->success([
                    'configuration' => $this->serialize($configuration),
                    'qrcode' => $this->qrcodeFrom($remote),
                ], 'Instância criada. Escaneie o QR Code.');
            }
            catch (EvolutionException $exception) {
                return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
            }
            catch (ConnectionException) {
                return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
            }
            catch (Throwable $exception) {
                try {
                    $this->evolution->deleteInstance($instanceName);
                }
                catch (Throwable) {
                }
                report($exception);

                return $this->error('Não foi possível salvar a configuração.', 'CONFIGURATION_SAVE_FAILED', 500);
            }
        });
    }

    public function connection(Filial $filial): JsonResponse
    {
        $configuration = $filial->whatsappConfiguration;

        if (!$configuration) {
            return $this->error('A filial não possui configuração de WhatsApp.', 'WHATSAPP_NOT_CONFIGURED', 404);
        }

        try {
            $remote = $this->evolution->connectionState($configuration);
            $state = data_get($remote, 'instance.state') ?? data_get($remote, 'state') ?? 'disconnected';
            $status = match ($state) {
                'open', 'connected' => 'connected',
                'connecting' => $configuration->provider === WhatsAppConfiguration::PROVIDER_BAILEYS ? 'waiting_qr' : 'pending_webhook',
                default => 'disconnected',
            };

            $configuration->update([
                'status' => $status,
                'connected_phone' => $this->connectedPhoneFrom($remote) ?? $configuration->connected_phone,
                'connected_at' => $status === 'connected' ? ($configuration->connected_at ?? now()) : $configuration->connected_at,
                'last_checked_at' => now(),
                'last_error' => null,
            ]);

            return $this->success($this->serialize($configuration->fresh('templates')));
        }
        catch (EvolutionException $exception) {
            $configuration->update(['last_checked_at' => now(), 'last_error' => $exception->getMessage()]);

            return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
        }
        catch (ConnectionException) {
            $configuration->update(['last_checked_at' => now(), 'last_error' => 'Evolution indisponível.']);

            return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
        }
    }

    public function qrcode(Filial $filial): JsonResponse
    {
        $configuration = $filial->whatsappConfiguration;

        if (!$configuration || $configuration->provider !== WhatsAppConfiguration::PROVIDER_BAILEYS) {
            return $this->error('A filial não possui uma instância por QR Code.', 'WHATSAPP_NOT_CONFIGURED', 404);
        }

        try {
            $remote = $this->evolution->connect($configuration);
            $configuration->update(['status' => 'waiting_qr', 'last_checked_at' => now(), 'last_error' => null]);

            return $this->success(['configuration' => $this->serialize($configuration), 'qrcode' => $this->qrcodeFrom($remote)]);
        }
        catch (EvolutionException $exception) {
            return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
        }
        catch (ConnectionException) {
            return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
        }
    }

    public function officialTemplates(Filial $filial): JsonResponse
    {
        $configuration = $filial->whatsappConfiguration;

        if (!$configuration || $configuration->provider !== WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            return $this->error('A filial não possui uma instância oficial.', 'WHATSAPP_NOT_CONFIGURED', 404);
        }

        try {
            return $this->success($this->approvedTemplates($configuration));
        }
        catch (EvolutionException $exception) {
            return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
        }
        catch (ConnectionException) {
            return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
        }
    }

    public function templates(Request $request, Filial $filial): JsonResponse
    {
        $configuration = $filial->whatsappConfiguration;

        if (!$configuration || $configuration->provider !== WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            return $this->error('A filial não possui uma instância oficial.', 'WHATSAPP_NOT_CONFIGURED', 404);
        }

        $data = $request->validate([
            'templates' => ['required', 'array'],
            'templates.*.event' => ['required', Rule::in(WhatsAppTemplate::EVENTS)],
            'templates.*.template_name' => ['required', 'regex:/^[a-z0-9_]+$/', 'max:255'],
            'templates.*.language_code' => ['required', 'string', 'max:15'],
            'templates.*.status' => ['sometimes', Rule::in(['APPROVED'])],
        ]);

        try {
            $approved = $this->approvedTemplates($configuration);
        }
        catch (EvolutionException $exception) {
            return $this->error($exception->getMessage(), $exception->errorCode, $exception->httpStatus);
        }
        catch (ConnectionException) {
            return $this->error('A Evolution API está indisponível.', 'EVOLUTION_UNAVAILABLE', 503);
        }

        foreach ($data['templates'] as $template) {
            $match = $approved->first(
                fn(array $approvedTemplate) => $approvedTemplate['name'] === $template['template_name']
                && $approvedTemplate['language_code'] === $template['language_code'],
            );

            if (!$match) {
                return $this->error('Um dos templates não está aprovado ou não existe na Meta.', 'TEMPLATE_NOT_APPROVED', 422);
            }
        }

        DB::transaction(function () use ($configuration, $data) {
            foreach ($data['templates'] as $template) {
                $configuration->templates()->updateOrCreate(
                    ['event' => $template['event']],
                    [
                        'template_name' => $template['template_name'],
                        'language_code' => $template['language_code'],
                        'status' => 'APPROVED',
                    ],
                );
            }
        });

        $this->audit($request, $filial, 'templates_updated');

        return $this->success($this->serialize($configuration->fresh('templates')), 'Templates atualizados.');
    }

    public function test(Request $request, Filial $filial): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20'],
            'event' => ['nullable', Rule::in(WhatsAppTemplate::EVENTS)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $configuration = $filial->whatsappConfiguration;

        if (!$configuration) {
            return $this->error('A filial não possui configuração de WhatsApp.', 'WHATSAPP_NOT_CONFIGURED', 404);
        }

        if ($configuration->status !== 'connected') {
            return $this->error('O WhatsApp da filial não está conectado.', 'INSTANCE_NOT_CONNECTED', 409);
        }

        $event = $configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL
            ? ($data['event'] ?? 'manual_notification')
            : null;

        if ($event && !$configuration->templates()->where('event', $event)->where('status', 'APPROVED')->exists()) {
            return $this->error('Configure um template aprovado para este evento.', 'TEMPLATE_NOT_APPROVED', 422);
        }

        EnviarMensagemWhatsAppJob::dispatch(
            $filial->id,
            preg_replace('/\D/', '', $data['phone']),
            'text',
            $data['message'] ?? 'Mensagem de teste do sistema Cobeb.',
            null,
            null,
            $event,
            [$data['message'] ?? 'Mensagem de teste do sistema Cobeb.'],
        );

        $this->audit($request, $filial, 'test_queued');

        return $this->success(null, 'Mensagem de teste enfileirada.', 202);
    }

    public function reconnect(Filial $filial): JsonResponse
    {
        return $filial->whatsappConfiguration?->provider === WhatsAppConfiguration::PROVIDER_BAILEYS
            ? $this->qrcode($filial)
            : $this->connection($filial);
    }

    public function destroy(Request $request, Filial $filial): JsonResponse
    {
        $configuration = $filial->whatsappConfiguration;

        if (!$configuration) {
            return $this->success(null, 'A filial já não possui configuração.');
        }

        try {
            $this->evolution->deleteInstance($configuration->instance_name, $configuration->instance_api_key);
        }
        catch (EvolutionException $exception) {
            if (!$request->boolean('force')) {
                return $this->error($exception->getMessage(), 'REMOTE_DELETE_FAILED', 502);
            }
        }
        catch (ConnectionException) {
            if (!$request->boolean('force')) {
                return $this->error('A Evolution API está indisponível.', 'REMOTE_DELETE_FAILED', 503);
            }
        }

        $configuration->delete();
        $this->audit($request, $filial, 'configuration_deleted');

        return $this->success(null, 'Configuração removida.');
    }

    private function locked(Filial $filial, callable $callback): JsonResponse
    {
        try {
            return Cache::lock("whatsapp-config:{$filial->id}", 30)->block(5, $callback);
        }
        catch (Throwable $exception) {
            report($exception);

            return $this->error('Já existe uma operação em andamento para esta filial.', 'CONFIGURATION_BUSY', 409);
        }
    }

    private function instanceName(Filial $filial): string
    {
        return Str::limit('cobeb-' . Str::slug($filial->codigo) . '-' . Str::lower(Str::random(8)), 60, '');
    }

    private function deletePreviousInstance(?WhatsAppConfiguration $previous, string $currentName): void
    {
        if (!$previous || $previous->instance_name === $currentName) {
            return;
        }

        try {
            $this->evolution->deleteInstance($previous->instance_name, $previous->instance_api_key);
        }
        catch (Throwable $exception) {
            Log::warning('Não foi possível remover a instância anterior da Evolution.', [
                'filial_id' => $previous->filial_id,
                'instance_name' => $previous->instance_name,
            ]);
        }
    }

    private function qrcodeFrom(array $response): ?array
    {
        $base64 = data_get($response, 'qrcode.base64') ?? data_get($response, 'base64');

        if (!$base64) {
            return null;
        }

        return [
            'base64' => str_starts_with($base64, 'data:image') ? $base64 : "data:image/png;base64,{$base64}",
            'expires_at' => now()->addSeconds((int) config('evolution.qrcode_expires_in', 60))->toIso8601String(),
        ];
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

    private function approvedTemplates(WhatsAppConfiguration $configuration)
    {
        $remote = $this->evolution->templates($configuration);
        $items = data_get($remote, 'data', $remote);
        $items = is_array($items) ? $items : [];

        return collect($items)
            ->filter(fn($item) => strtoupper((string) data_get($item, 'status', 'APPROVED')) === 'APPROVED')
            ->map(function ($item) {
                $language = data_get($item, 'language.code') ?? data_get($item, 'language');

                return [
                    'name' => data_get($item, 'name'),
                    'language_code' => is_string($language) ? $language : 'pt_BR',
                    'status' => data_get($item, 'status', 'APPROVED'),
                ];
            })->filter(fn($item) => filled($item['name']))->values()
        ;
    }

    private function serialize(?WhatsAppConfiguration $configuration): ?array
    {
        if (!$configuration) {
            return null;
        }

        return [
            'id' => $configuration->id,
            'filial_id' => $configuration->filial_id,
            'provider' => $configuration->provider,
            'instance_name' => $configuration->instance_name,
            'status' => $configuration->status,
            'connected_at' => $configuration->connected_at?->toIso8601String(),
            'last_checked_at' => $configuration->last_checked_at?->toIso8601String(),
            'last_error' => $configuration->last_error,
            'token_configured' => filled($configuration->getRawOriginal('meta_access_token')),
            'access_token_masked' => filled($configuration->getRawOriginal('meta_access_token')) ? '••••••••' : null,
            'connected_phone' => $configuration->connected_phone,
            'phone_number_id' => $configuration->meta_phone_number_id,
            'business_account_id' => $configuration->meta_business_account_id,
            'token_expires_at' => $configuration->token_expires_at?->toIso8601String(),
            'templates' => $configuration->relationLoaded('templates')
                ? $configuration->templates->map->only(['event', 'template_name', 'language_code', 'status'])->values()
                : [],
            'meta_webhook' => $configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL ? [
                'url' => config('evolution.meta_webhook_url'),
                'verify_token' => config('evolution.meta_webhook_token'),
            ] : null,
        ];
    }

    private function audit(Request $request, Filial $filial, string $action): void
    {
        Log::notice('Configuração WhatsApp alterada.', [
            'action' => $action,
            'filial_id' => $filial->id,
            'user_id' => $request->user()?->id,
        ]);
    }

    private function success(mixed $data = null, string $message = 'Operação realizada com sucesso.', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, string $errorCode, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $errorCode, 'data' => null], $status);
    }
}
