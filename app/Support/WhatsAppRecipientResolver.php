<?php

namespace App\Support;

use App\Exceptions\WhatsAppNotConfiguredException;
use App\Models\Cliente;
use App\Models\ClienteTelefones;
use App\Models\WhatsAppConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WhatsAppRecipientResolver
{
    public function __construct(
        private readonly BrazilianPhoneNumber $phones,
        private readonly EvolutionClient $evolution,
    ) {}

    /**
     * @return array{configuration: WhatsAppConfiguration, contact: ClienteTelefones|null, phone: string|null}
     */
    public function resolve(Cliente $cliente, string $filialId): array
    {
        $configuration = $this->configuration($filialId);
        $contacts = $cliente->contatos()
            ->orderByDesc('isWhatsapp')
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn(ClienteTelefones $contact) => $this->phones->tryNational($contact->numero) !== null)
            ->values()
        ;

        if ($contacts->isEmpty()) {
            return compact('configuration') + ['contact' => null, 'phone' => null];
        }

        if ($configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            $contact = $contacts->first();

            return compact('configuration', 'contact') + ['phone' => $this->phones->national($contact->numero)];
        }

        return $this->resolveBaileys($configuration, $contacts);
    }

    /**
     * @return array{configuration: WhatsAppConfiguration, phone: string, exists: bool}
     */
    public function validate(string $filialId, string $phone): array
    {
        $configuration = $this->configuration($filialId);
        $national = $this->phones->national($phone);

        if ($configuration->provider === WhatsAppConfiguration::PROVIDER_OFFICIAL) {
            return ['configuration' => $configuration, 'phone' => $national, 'exists' => true];
        }

        $international = $this->phones->international($national);
        $result = $this->evolution->whatsappNumbers($configuration, [$international]);

        return [
            'configuration' => $configuration,
            'phone' => $national,
            'exists' => (bool) ($result[$international] ?? false),
        ];
    }

    public function promote(Cliente $cliente, string $phone, string $provider): ClienteTelefones
    {
        $national = $this->phones->national($phone);

        return DB::transaction(function () use ($cliente, $national, $provider) {
            $cliente->contatos()->where('numero', '!=', $national)->update(['isWhatsapp' => false]);

            return $cliente->contatos()->updateOrCreate(
                ['numero' => $national],
                [
                    'isWhatsapp' => true,
                    'whatsapp_validation_status' => 'valid',
                    'whatsapp_verified_at' => now(),
                    'whatsapp_validation_provider' => $provider,
                ],
            );
        });
    }

    private function configuration(string $filialId): WhatsAppConfiguration
    {
        $configuration = WhatsAppConfiguration::resolveForFilial($filialId);

        if (!$configuration) {
            throw new WhatsAppNotConfiguredException('A filial não possui configuração de WhatsApp.');
        }

        if ($configuration->status !== 'connected') {
            throw new WhatsAppNotConfiguredException('O WhatsApp da filial não está conectado.');
        }

        return $configuration;
    }

    /**
     * @param  Collection<int, ClienteTelefones>  $contacts
     * @return array{configuration: WhatsAppConfiguration, contact: ClienteTelefones|null, phone: string|null}
     */
    private function resolveBaileys(WhatsAppConfiguration $configuration, Collection $contacts): array
    {
        $byInternational = [];

        foreach ($contacts as $contact) {
            $byInternational[$this->phones->international($contact->numero)] = $contact;
        }

        $result = $this->evolution->whatsappNumbers($configuration, array_keys($byInternational));
        $selected = null;

        foreach ($byInternational as $international => $contact) {
            $exists = (bool) ($result[$international] ?? false);
            $contact->forceFill([
                'whatsapp_validation_status' => $exists ? 'valid' : 'invalid',
                'whatsapp_verified_at' => now(),
                'whatsapp_validation_provider' => WhatsAppConfiguration::PROVIDER_BAILEYS,
                'isWhatsapp' => $exists && $selected === null,
            ])->save();

            if ($exists && $selected === null) {
                $selected = $contact;
            }
        }

        if ($selected) {
            $selected->cliente->contatos()->whereKeyNot($selected->getKey())->update(['isWhatsapp' => false]);
        }

        return [
            'configuration' => $configuration,
            'contact' => $selected,
            'phone' => $selected ? $this->phones->national($selected->numero) : null,
        ];
    }
}
