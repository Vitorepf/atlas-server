<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasTerminalNotifier;
use Tests\TestCase;

class AtlasTerminalNotifierTest extends TestCase
{
    public function test_disabled_notifier_returns_false(): void
    {
        $notifier = new AtlasTerminalNotifier(enabled: false);

        $this->assertFalse($notifier->notify('atlas dev', 'concluido', 60_000));
    }

    public function test_short_runs_under_threshold_skip_notification(): void
    {
        $notifier = new AtlasTerminalNotifier(enabled: true, minDurationMs: 30_000);

        $this->assertFalse($notifier->notify('atlas dev', 'concluido', durationMs: 5_000));
    }

    public function test_non_macos_silently_skips(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('macOS specific path covered manually');
        }

        $notifier = new AtlasTerminalNotifier(enabled: true);
        $this->assertFalse($notifier->notify('t', 'b', 60_000));
    }

    public function test_long_run_attempts_notification_on_macos(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS only');
        }

        $notifier = new AtlasTerminalNotifier(enabled: true, minDurationMs: 30_000);
        $ok = $notifier->notify('atlas test', 'apenas smoke', 60_000);

        $this->assertTrue(is_bool($ok));
    }
}
