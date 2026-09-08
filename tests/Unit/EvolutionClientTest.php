<?php

namespace Tests\Unit;

use App\Support\EvolutionClient;
use App\Models\WhatsAppConfiguration;
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

    public function test_it_reads_the_whatsapp_number_array_returned_by_evolution(): void
    {
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            ['jid' => '553732590820@s.whatsapp.net', 'exists' => false, 'number' => '553732590820'],
            ['jid' => '5537998247669@s.whatsapp.net', 'exists' => true, 'number' => '5537998247669'],
        ])]);
        $configuration = new WhatsAppConfiguration(['instance_name' => 'cobeb-1']);

        $result = app(EvolutionClient::class)->whatsappNumbers($configuration, ['553732590820', '5537998247669']);

        $this->assertFalse($result['553732590820']);
        $this->assertTrue($result['5537998247669']);
    }

    public function test_it_reads_the_documented_wrapped_number_response(): void
    {
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            'numbers' => [['number' => '5537998247669', 'exists' => true]],
        ])]);
        $configuration = new WhatsAppConfiguration(['instance_name' => 'cobeb-1']);

        $result = app(EvolutionClient::class)->whatsappNumbers($configuration, ['5537998247669']);

        $this->assertTrue($result['5537998247669']);
    }
}
