<?php

namespace App\Support;

use App\Exceptions\WhatsAppNotConfiguredException;
use App\Models\WhatsAppConfiguration;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class WhatsAppService
{
    public function __construct(private readonly EvolutionClient $evolution) {}

    public function sendMessage(string $filialId, string $phone, string $text, ?string $event = null, array $variables = []): bool
    {
        $configuration = $this->configuration($filialId);
        $number = $this->destination($phone);

        if ($configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            $template = $configuration->templates()->where('event', $event ?? 'manual_notification')->first();

            if (!$template || strtoupper($template->status) !== 'APPROVED') {
                throw new WhatsAppNotConfiguredException('Não existe template aprovado para este evento.');
            }

            $this->evolution->sendTemplate(
                $configuration,
                $number,
                $template->template_name,
                $template->language_code,
                $variables !== [] ? $variables : [$text],
            );

            return true;
        }

        $this->evolution->sendText($configuration, $number, $text);

        return true;
    }

    public function sendMedia(
        string $filialId,
        string $phone,
        string $caption,
        string $storagePath,
        string $fileName,
        ?string $event = 'import_report',
        array $variables = [],
    ): bool {
        $configuration = $this->configuration($filialId);
        $number = $this->destination($phone);

        if (!Storage::exists($storagePath)) {
            throw new \RuntimeException('Arquivo de mídia não encontrado.');
        }

        if ($configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            $template = $configuration->templates()->where('event', $event)->first();

            if (!$template || strtoupper($template->status) !== 'APPROVED') {
                throw new WhatsAppNotConfiguredException('Não existe template aprovado para o relatório.');
            }

            $encodedPath = rtrim(strtr(base64_encode($storagePath), '+/', '-_'), '=');
            $url = URL::temporarySignedRoute('whatsapp.media', now()->addMinutes(15), ['encodedPath' => $encodedPath]);
            $this->evolution->sendTemplate(
                $configuration,
                $number,
                $template->template_name,
                $template->language_code,
                $variables,
                ['url' => $url, 'name' => $fileName],
            );

            return true;
        }

        $this->evolution->sendMedia(
            $configuration,
            $number,
            $caption,
            base64_encode(Storage::get($storagePath)),
            $fileName,
        );

        return true;
    }

    private function configuration(string $filialId): WhatsAppConfiguration
    {
        $configuration = WhatsAppConfiguration::query()->with('templates')->where('filial_id', $filialId)->first();

        if (!$configuration) {
            throw new WhatsAppNotConfiguredException('A filial não possui configuração de WhatsApp.');
        }

        if ($configuration->status !== 'connected') {
            throw new WhatsAppNotConfiguredException('O WhatsApp da filial não está conectado.');
        }

        return $configuration;
    }

    private function destination(string $phone): string
    {
        $number = $this->formatNumber($phone);

        if (!app()->environment('production') && filled(config('evolution.default_number'))) {
            return $this->formatNumber((string) config('evolution.default_number'));
        }

        return $number;
    }

    private function formatNumber(string $phone): string
    {
        $cleaned = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($cleaned) <= 11 ? '55' . $cleaned : $cleaned;
    }
}
