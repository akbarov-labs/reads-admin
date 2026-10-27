<?php

namespace App\Services\Softly;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only way this app talks to Softly. Softly let us read its API on the
 * condition that we keep the load low, so every request here:
 *
 *  - waits `delay_ms` after the one before, one at a time;
 *  - counts toward `daily_cap`, and none is sent once it is spent;
 *  - on any failure (an error status, a 429, no answer, a reply that is
 *    not the list we expect) stops all requests for `pause_minutes`, or
 *    longer when Softly sends Retry-After. Nothing is retried in a loop.
 */
class SoftlyClient
{
    private const PAUSED_KEY = 'softly:paused_until';

    private ?float $lastRequestAt = null;

    /**
     * One page of Softly's book list, newest change first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function books(int $page): array
    {
        $this->guard();
        $this->wait();

        $countKey = 'softly:requests:'.now()->toDateString();
        Cache::add($countKey, 0, now()->addDays(2));
        Cache::increment($countKey);

        try {
            $response = Http::withUserAgent($this->config('user_agent'))
                ->acceptJson()
                ->timeout(20)
                ->get(rtrim($this->config('url'), '/').'/books', [
                    'page' => $page,
                    'size' => $this->config('page_size'),
                ]);
        } catch (ConnectionException $e) {
            $this->pause('no answer: '.$e->getMessage());
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        if (! $response->successful()) {
            $this->pause("HTTP {$response->status()}", (int) $response->header('Retry-After'));
        }

        $items = $response->json('items');
        $total = $response->json('totalCount');

        if (! is_array($items) || ! is_int($total)) {
            $this->pause('the reply is not a book list');
        }

        return ['items' => array_values($items), 'total' => $total];
    }

    public function pausedUntil(): ?CarbonImmutable
    {
        $until = Cache::get(self::PAUSED_KEY);

        return $until !== null && CarbonImmutable::parse($until)->isFuture() ? CarbonImmutable::parse($until) : null;
    }

    public function requestsToday(): int
    {
        return (int) Cache::get('softly:requests:'.now()->toDateString(), 0);
    }

    private function guard(): void
    {
        if ($until = $this->pausedUntil()) {
            throw new SoftlyUnavailable("Paused after an error until {$until->toDateTimeString()} UTC.");
        }

        if ($this->requestsToday() >= $this->config('daily_cap')) {
            throw new SoftlyUnavailable("Today's cap of {$this->config('daily_cap')} requests is spent.");
        }
    }

    private function wait(): void
    {
        if ($this->lastRequestAt === null) {
            return;
        }

        $left = $this->config('delay_ms') / 1000 - (microtime(true) - $this->lastRequestAt);

        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }

    private function pause(string $why, int $retryAfterSeconds = 0): never
    {
        $until = now()->addSeconds(max($this->config('pause_minutes') * 60, $retryAfterSeconds));
        Cache::put(self::PAUSED_KEY, $until->toIso8601String(), $until);

        Log::warning("Softly: {$why}. No requests until {$until->toDateTimeString()} UTC.");

        throw new SoftlyUnavailable("Softly: {$why}. Paused until {$until->toDateTimeString()} UTC.");
    }

    private function config(string $key): mixed
    {
        return config("services.softly.{$key}");
    }
}
