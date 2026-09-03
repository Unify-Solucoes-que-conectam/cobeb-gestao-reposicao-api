<?php

namespace App\Support;

use App\Exceptions\EvolutionException;
use App\Models\WhatsAppConfiguration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EvolutionClient
{
    private string $baseUrl;

    private string $masterKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('evolution.base_url'), '/');
        $this->masterKey = (string) config('evolution.api_key');
    }

    public function createOfficial(string $instanceName, string $token, string $phoneNumberId, string $businessId): array
    {
        return $this->json($this->request()->post("{$this->baseUrl}/instance/create", [
            'instanceName' => $instanceName,
            'integration' => 'WHATSAPP-BUSINESS',
            'token' => $token,
            'number' => $phoneNumberId,
            'businessId' => $businessId,
        ]), 'Não foi possível criar a instância oficial.', 'INVALID_PROVIDER_CREDENTIALS');
    }

    public function createBaileys(string $instanceName): array
    {
        return $this->json($this->request()->post("{$this->baseUrl}/instance/create", [
            'instanceName' => $instanceName,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ]), 'Não foi possível criar a instância por QR Code.');
    }

    public function connectionState(WhatsAppConfiguration $configuration): array
    {
        return $this->json(
            $this->request($configuration)->get("{$this->baseUrl}/instance/connectionState/{$configuration->instance_name}"),
            'Não foi possível consultar a conexão.',
        );
    }

    public function connect(WhatsAppConfiguration $configuration): array
    {
        return $this->json(
            $this->request($configuration)->get("{$this->baseUrl}/instance/connect/{$configuration->instance_name}"),
            'Não foi possível gerar a conexão.',
            'QR_CODE_EXPIRED',
        );
    }

    public function templates(WhatsAppConfiguration $configuration): array
    {
        return $this->json(
            $this->request($configuration)->get("{$this->baseUrl}/template/find/{$configuration->instance_name}"),
            'Não foi possível consultar os templates oficiais.',
        );
    }

    public function instances(): array
    {
        return $this->json(
            $this->request()->get("{$this->baseUrl}/instance/fetchInstances"),
            'Não foi possível listar as instâncias da Evolution.',
        );
    }

    public function deleteInstance(string $instanceName, ?string $instanceKey = null): void
    {
        $this->json(
            $this->requestWithKey($instanceKey)->delete("{$this->baseUrl}/instance/delete/{$instanceName}"),
            'Não foi possível excluir a instância na Evolution.',
        );
    }

    public function sendText(WhatsAppConfiguration $configuration, string $number, string $text): void
    {
        $this->json($this->request($configuration)->post(
            "{$this->baseUrl}/message/sendText/{$configuration->instance_name}",
            ['number' => $number, 'text' => $text],
        ), 'A Evolution recusou a mensagem de texto.');
    }

    public function sendMedia(
        WhatsAppConfiguration $configuration,
        string $number,
        string $caption,
        string $media,
        string $fileName,
    ): void {
        $this->json($this->request($configuration)->post(
            "{$this->baseUrl}/message/sendMedia/{$configuration->instance_name}",
            [
                'number' => $number,
                'mediatype' => 'document',
                'mimetype' => 'application/pdf',
                'caption' => $caption,
                'media' => $media,
                'fileName' => $fileName,
            ],
        ), 'A Evolution recusou o documento.');
    }

    public function sendTemplate(
        WhatsAppConfiguration $configuration,
        string $number,
        string $name,
        string $language,
        array $variables = [],
        ?array $document = null,
    ): void {
        $components = [];

        if ($document) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => ['link' => $document['url'], 'filename' => $document['name']],
                ]],
            ];
        }

        if ($variables !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn(mixed $value) => ['type' => 'text', 'text' => (string) $value],
                    array_values($variables),
                ),
            ];
        }

        $this->json($this->request($configuration)->post(
            "{$this->baseUrl}/message/sendTemplate/{$configuration->instance_name}",
            [
                'number' => $number,
                'name' => $name,
                'language' => $language,
                'components' => $components,
            ],
        ), 'A Evolution recusou o template.', 'TEMPLATE_SEND_FAILED');
    }

    private function request(?WhatsAppConfiguration $configuration = null): PendingRequest
    {
        return $this->requestWithKey($configuration?->instance_api_key);
    }

    private function requestWithKey(?string $key = null): PendingRequest
    {
        if ($this->baseUrl === '' || ($key ?: $this->masterKey) === '') {
            throw new EvolutionException('A Evolution API não está configurada no servidor.', 'EVOLUTION_NOT_CONFIGURED', 503);
        }

        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['apikey' => $key ?: $this->masterKey])
            ->connectTimeout((int) config('evolution.connect_timeout', 5))
            ->timeout((int) config('evolution.timeout', 20))
        ;
    }

    private function json(Response $response, string $message, string $errorCode = 'EVOLUTION_ERROR'): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw new EvolutionException(
            $message,
            $errorCode,
            $response->status() === 401 || $response->status() === 403 ? 422 : 502,
        );
    }
}
