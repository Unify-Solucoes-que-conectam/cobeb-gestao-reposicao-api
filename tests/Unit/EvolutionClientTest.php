<?php

namespace Tests\Unit;

use App\Support\EvolutionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('evolution.base_url', 'http://evolution-api:8080');
        config()->set('evolution.api_key', 'master-key-for-tests');
    }

    public function test_it_creates_an_official_instance_with_the_expected_contract(): void
    {
        Http::fake(['*/instance/create' => Http::response(['instance' => ['instanceName' => 'cobeb-1']], 201)]);

        app(EvolutionClient::class)->createOfficial('cobeb-1', 'meta-token', 'phone-id', 'business-id');

        Http::assertSent(
            fn(Request $request) => $request->url() === 'http://evolution-api:8080/instance/create'
            && $request->hasHeader('apikey', 'master-key-for-tests')
            && $request['integration'] === 'WHATSAPP-BUSINESS'
            && $request['token'] === 'meta-token'
            && $request['number'] === 'phone-id'
            && $request['businessId'] === 'business-id',
        );
    }

    public function test_it_creates_a_baileys_instance_requesting_a_qr_code(): void
    {
        Http::fake(['*/instance/create' => Http::response(['qrcode' => ['base64' => 'image']], 201)]);

        app(EvolutionClient::class)->createBaileys('cobeb-2');

        Http::assertSent(
            fn(Request $request) => $request['integration'] === 'WHATSAPP-BAILEYS'
            && $request['qrcode'] === true,
        );
    }
}
