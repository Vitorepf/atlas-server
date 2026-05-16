<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Services\Integration\FakeProviderClient;
use PHPUnit\Framework\TestCase;

final class FakeProviderClientTest extends TestCase
{
    public function test_returns_on_first_success(): void
    {
        $dispatcher = fn () => ['status' => 'ok', 'payload' => ['data' => 42], 'latency_seconds' => 1];
        $client = new FakeProviderClient($dispatcher, timeoutSeconds: 5, maxAttempts: 3, clock: fn () => 100);

        $result = $client->call();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(['data' => 42], $result['payload']);
    }

    public function test_retries_up_to_limit_then_hard_blocker(): void
    {
        $dispatcher = fn () => ['status' => 'flaky', 'latency_seconds' => 1];
        $client = new FakeProviderClient($dispatcher, timeoutSeconds: 30, maxAttempts: 3, clock: fn () => 100);

        $result = $client->call();

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('max_attempts_reached', $result['reason'] ?? '');
        $this->assertSame(3, $result['attempts']);
    }

    public function test_hard_timeout_returns_blocker(): void
    {
        // dispatcher sempre devolve 'timeout' com latência > timeoutSeconds.
        $dispatcher = fn () => ['status' => 'timeout', 'latency_seconds' => 10];
        $tick = 100;
        $clock = function () use (&$tick): int {
            $now = $tick;
            $tick += 10; // cada call simula 10s de elapsed
            return $now;
        };
        $client = new FakeProviderClient($dispatcher, timeoutSeconds: 5, maxAttempts: 10, clock: $clock);

        $result = $client->call();

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('timeout', $result['reason'] ?? '');
        $this->assertGreaterThanOrEqual(1, $result['attempts']);
    }
}
