<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Domain\Notifications\ChannelGuard;
use App\Domain\Notifications\NotificationDispatcher;
use PHPUnit\Framework\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    public function test_slow_channel_does_not_starve_others(): void
    {
        $dispatcher = new NotificationDispatcher;
        $guards = [
            'email' => new ChannelGuard('email', budgetMillis: 100),
            'slack' => new ChannelGuard('slack', budgetMillis: 100),
            'sms' => new ChannelGuard('sms', budgetMillis: 100),
            'webhook' => new ChannelGuard('webhook', budgetMillis: 100),
        ];
        $channels = [
            'email' => fn (array $p) => 5,
            'slack' => fn (array $p) => 250,   // slow — exceeds budget
            'sms' => fn (array $p) => 10,
            'webhook' => fn (array $p) => 20,
        ];

        $report = $dispatcher->dispatchAll(['x' => 1], $channels, $guards);

        $this->assertSame('ok', $report['email']['status']);
        $this->assertSame('timed_out', $report['slack']['status'], 'slack should report timed_out, not block others');
        $this->assertSame('ok', $report['sms']['status']);
        $this->assertSame('ok', $report['webhook']['status']);
    }

    public function test_circuit_breaker_opens_after_repeated_failures(): void
    {
        $dispatcher = new NotificationDispatcher;
        $guard = new ChannelGuard('slack', budgetMillis: 50, tripAfter: 2);
        $guards = ['slack' => $guard];
        $channels = ['slack' => fn (array $p) => 500];

        $dispatcher->dispatchAll(['x' => 1], $channels, $guards);
        $dispatcher->dispatchAll(['x' => 2], $channels, $guards);
        $this->assertTrue($guard->isOpen(), 'guard should open after 2 consecutive timeouts');

        $report = $dispatcher->dispatchAll(['x' => 3], $channels, $guards);
        $this->assertSame('circuit_open', $report['slack']['status']);
    }
}
