<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

class SpaceWhatsAppMessages
{
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;
    private const SLOT_TTL_SECONDS = 86400;

    public function handle(object $job, Closure $next): mixed
    {
        $key = $this->cacheKey($job->rateLimitKey());
        $intervalMilliseconds = $this->intervalMilliseconds();

        $slot = Cache::lock($key . ':lock', self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($key, $intervalMilliseconds): int {
                $now = $this->nowMilliseconds();
                $nextAvailableAt = max($now, (int) Cache::get($key . ':next', 0));

                Cache::put(
                    $key . ':next',
                    $nextAvailableAt + $intervalMilliseconds,
                    self::SLOT_TTL_SECONDS,
                );

                return $nextAvailableAt;
            })
        ;

        $waitMilliseconds = max(0, $slot - $this->nowMilliseconds());

        if ($waitMilliseconds > 0) {
            Sleep::usleep($waitMilliseconds * 1000);
        }

        return $next($job);
    }

    private function intervalMilliseconds(): int
    {
        $perMinute = max(1, min(60, (int) config('evolution.rate_limit', 10)));

        return (int) ceil(60000 / $perMinute);
    }

    private function cacheKey(string $rateLimitKey): string
    {
        return 'whatsapp-send-slot:' . hash('sha256', $rateLimitKey);
    }

    private function nowMilliseconds(): int
    {
        return (int) now()->format('Uv');
    }
}
