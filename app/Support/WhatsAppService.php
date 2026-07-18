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

    public function __construct()
    {
        $this->baseUrl = rtrim(config('evolution.base_url'), '/');
        $this->apiKey = config('evolution.api_key');
        $this->instance = config('evolution.instance');
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
                'number' => $number,
                'text' => $text,
            ]);

            if ($response->successful()) {
                Log::info('Mensagem enviada', ['number' => $number]);
                return true;
            }

            Log::error('Erro ao enviar mensagem', [
                'number' => $number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Exceção ao enviar mensagem', [
                'number' => $number,
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
