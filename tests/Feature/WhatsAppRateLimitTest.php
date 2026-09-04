<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagemWhatsAppJob;
use App\Jobs\Middleware\SpaceWhatsAppMessages;
use App\Models\Filial;
use App\Models\WhatsAppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class WhatsAppRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('evolution.rate_limit', 10);
        Carbon::setTestNow('2026-09-03 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_global_configuration_shares_the_same_limit_key_between_branches(): void
    {
        $owner = Filial::create(['codigo' => '10', 'descricao' => 'Matriz']);
        $other = Filial::create(['codigo' => '20', 'descricao' => 'Filial']);
        $configuration = $this->configuration($owner, 'cobeb-global', true);

        $this->assertSame(
            'configuration:' . $configuration->id,
            $this->job($owner)->rateLimitKey(),
        );
        $this->assertSame(
            $this->job($owner)->rateLimitKey(),
            $this->job($other)->rateLimitKey(),
        );
    }

    public function test_individual_configurations_have_independent_limit_keys(): void
    {
        $first = Filial::create(['codigo' => '30', 'descricao' => 'Filial 30']);
        $second = Filial::create(['codigo' => '40', 'descricao' => 'Filial 40']);
        $this->configuration($first, 'cobeb-30');
        $this->configuration($second, 'cobeb-40');

        $this->assertNotSame(
            $this->job($first)->rateLimitKey(),
            $this->job($second)->rateLimitKey(),
        );
    }

    public function test_messages_for_the_same_instance_are_started_six_seconds_apart(): void
    {
        $filial = Filial::create(['codigo' => '50', 'descricao' => 'Filial 50']);
        $this->configuration($filial, 'cobeb-50');
        $middleware = new SpaceWhatsAppMessages();
        $startedAt = [];

        for ($message = 0; $message < 10; $message++) {
            $middleware->handle($this->job($filial), function () use (&$startedAt): void {
                $startedAt[] = (int) now()->format('Uv');
            });
        }

        $intervals = collect($startedAt)
            ->zip(array_slice($startedAt, 1))
            ->filter(fn($pair) => $pair[1] !== null)
            ->map(fn($pair) => $pair[1] - $pair[0])
        ;

        $this->assertCount(9, $intervals);
        $this->assertTrue($intervals->every(fn(int $interval) => $interval >= 6000));
        Sleep::assertSleptTimes(9);
    }

    public function test_database_cache_store_supports_the_shared_reservation(): void
    {
        config()->set('cache.default', 'database');
        Cache::flush();

        $filial = Filial::create(['codigo' => '55', 'descricao' => 'Filial 55']);
        $this->configuration($filial, 'cobeb-55');
        $middleware = new SpaceWhatsAppMessages();
        $startedAt = [];

        for ($message = 0; $message < 2; $message++) {
            $middleware->handle($this->job($filial), function () use (&$startedAt): void {
                $startedAt[] = (int) now()->format('Uv');
            });
        }

        $this->assertSame(6000, $startedAt[1] - $startedAt[0]);
    }

    public function test_cache_failure_prevents_the_message_from_being_sent(): void
    {
        $filial = Filial::create(['codigo' => '60', 'descricao' => 'Filial 60']);
        $this->configuration($filial, 'cobeb-60');
        $nextCalled = false;

        Cache::shouldReceive('lock')->once()->andThrow(new RuntimeException('Cache unavailable'));

        try {
            (new SpaceWhatsAppMessages())->handle(
                $this->job($filial),
                function () use (&$nextCalled): void {
                    $nextCalled = true;
                },
            );
            $this->fail('The cache failure should have been propagated.');
        }
        catch (RuntimeException $exception) {
            $this->assertSame('Cache unavailable', $exception->getMessage());
        }

        $this->assertFalse($nextCalled);
    }

    public function test_job_keeps_three_real_attempts_and_uses_the_spacing_middleware(): void
    {
        $filial = Filial::create(['codigo' => '70', 'descricao' => 'Filial 70']);
        $job = $this->job($filial);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 60, 120], $job->backoff);
        $this->assertInstanceOf(SpaceWhatsAppMessages::class, $job->middleware()[0]);
    }

    private function job(Filial $filial): EnviarMensagemWhatsAppJob
    {
        return new EnviarMensagemWhatsAppJob(
            $filial->id,
            '37999999999',
            'text',
            'Mensagem de teste',
        );
    }

    private function configuration(Filial $filial, string $instanceName, bool $global = false): WhatsAppConfiguration
    {
        return WhatsAppConfiguration::create([
            'filial_id' => $filial->id,
            'is_global' => $global,
            'global_slot' => $global ? 'global' : null,
            'provider' => 'baileys',
            'instance_name' => $instanceName,
            'status' => 'connected',
        ]);
    }
}
