<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $instance;
    protected string $defaultNumber;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('evolution.base_url'), '/');
        $this->apiKey = config('evolution.api_key');
        $this->instance = config('evolution.instance');
        $this->defaultNumber = config('evolution.default_number');
    }

    public function sendMessage(string $phone, string $text): bool
    {
        $number = $this->formatNumber($phone);
        return $this->sendText($number, $text);
    }

    protected function sendText(string $number, string $text): bool
    {
        try {
            /** @var Response $response */
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendText/{$this->instance}", [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'text' => $text,
            ]);

            if ($response->successful()) {
                Log::info('Mensagem enviada', [$this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),]);
                return true;
            }

            Log::error('Erro ao enviar mensagem', [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Exceção ao enviar mensagem', [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendMedia(string $phone, string $mediatype, string $mimetype, string $caption, string $media, string $fileName): bool
    {
        $number = $this->formatNumber($phone);

        try {
            /** @var Response $response */
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendMedia/{$this->instance}", [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'mediatype' => $mediatype,
                'mimetype' => $mimetype,
                'caption' => $caption,
                'media' => $media,
                'fileName' => $fileName,
            ]);

            if ($response->successful()) {
                Log::info('Arquivo enviado', [$this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),]);
                return true;
            }

            Log::error('Erro ao enviar arquivo', [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => [
                    'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                    'mediatype' => $mediatype,
                    'mimetype' => $mimetype,
                    'caption' => $caption,
                    'media' => $media,
                    'fileName' => $fileName,
                ],
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Exceção ao enviar arquivo', [
                'number' => $this->formatNumber(config('app.env') !== 'production' ? $this->defaultNumber : $number),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function formatNumber(string $phone): string
    {
        // Remove tudo que não é dígito
        $cleaned = preg_replace('/\D/', '', $phone);

        // Se não tem código do país (55), adiciona
        if (strlen($cleaned) <= 11) {
            $cleaned = '55' . $cleaned;
        }

        return $cleaned;
    }
}
