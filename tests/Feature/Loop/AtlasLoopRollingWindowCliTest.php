<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopRollingWindowCli;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactStreamEmitter;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowAggregator;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowComparator;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowReceiptLedger;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class AtlasLoopRollingWindowCliTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
            if (is_dir($path)) {
                $this->deleteTree($path);
            }
        }

        parent::tearDown();
    }

    public function test_inspect_json_contains_fact_window_fields(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute([
            'action' => 'inspect',
            '--window' => '1h',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1h', $payload['window_label']);
        $this->assertSame('2026-06-24T13:00:00+00:00', $payload['window_start_iso']);
        $this->assertSame('2026-06-24T14:00:00+00:00', $payload['window_end_iso']);
        $this->assertArrayHasKey('counts', $payload);
        $this->assertArrayHasKey('durations_ms', $payload);
    }

    public function test_compare_json_contains_fact_deltas_without_score_rank_or_normalized_keys(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute([
            'action' => 'compare',
            '--window' => '6h',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('count_delta', $payload);
        $this->assertArrayHasKey('duration_delta_ms', $payload);
        $this->assertArrayHasKey('present_in_both', $payload['count_delta']['claim']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(score|rank|normalized)"\s*:/i', $encoded);
    }

    public function test_invalid_window_and_unknown_action_exit_with_code_two(): void
    {
        $tester = new CommandTester($this->command());
        $invalidWindowExit = $tester->execute([
            'action' => 'inspect',
            '--window' => '2h',
        ]);

        $this->assertSame(2, $invalidWindowExit);
        $this->assertStringContainsString('"error":"invalid_window"', $tester->getDisplay());

        $unknownActionExit = $tester->execute([
            'action' => 'mystery',
            '--window' => '1h',
        ]);

        $this->assertSame(2, $unknownActionExit);
        $this->assertStringContainsString('"error":"unknown_action"', $tester->getDisplay());
    }

    public function test_history_returns_persisted_snapshot_receipts_in_chronological_order(): void
    {
        $command = $this->command();
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'inspect',
            '--window' => '1h',
            '--at' => '2026-06-24T13:50:00+00:00',
            '--json' => true,
        ]);
        $tester->execute([
            'action' => 'inspect',
            '--window' => '1h',
            '--at' => '2026-06-24T14:50:00+00:00',
            '--json' => true,
        ]);

        $exit = $tester->execute([
            'action' => 'history',
            '--window' => '1h',
            '--since' => '2026-06-24T12:59:59+00:00',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1h', $payload['window_label']);
        $this->assertSame([
            '2026-06-24T13:00:00+00:00',
            '2026-06-24T14:00:00+00:00',
        ], array_column($payload['receipts'], 'window_start_iso'));
    }

    private function command(): AtlasLoopRollingWindowCli
    {
        $facts = [];
        $clockTicks = [
            '2026-06-24T13:10:00+00:00',
            '2026-06-24T13:12:00+00:00',
            '2026-06-24T13:20:00+00:00',
            '2026-06-24T13:30:00+00:00',
            '2026-06-24T08:10:00+00:00',
            '2026-06-24T08:12:00+00:00',
            '2026-06-24T08:20:00+00:00',
            '2026-06-24T08:30:00+00:00',
        ];

        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            static function (array $fact) use (&$facts): void {
                $facts[] = $fact;
            },
            static function () use (&$clockTicks): DateTimeImmutable {
                $value = array_shift($clockTicks) ?? '2026-06-24T13:30:00+00:00';

                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            }
        );

        $emitter->emit('claim', ['scope' => 'loop'], 'cycle-1');
        $emitter->emit('lease', ['scope' => 'loop'], 'cycle-1');
        $emitter->emit('serve', ['scope' => 'loop'], 'cycle-1');
        $emitter->emit('report', ['scope' => 'loop'], 'cycle-1');
        $emitter->emit('claim', ['scope' => 'loop'], 'cycle-2');
        $emitter->emit('lease', ['scope' => 'loop'], 'cycle-2');
        $emitter->emit('serve', ['scope' => 'loop'], 'cycle-2');
        $emitter->emit('report', ['scope' => 'loop'], 'cycle-2');

        $basePath = sys_get_temp_dir().'/atlas-loop-rolling-window-cli-'.bin2hex(random_bytes(6));
        mkdir($basePath, 0777, true);
        $this->paths[] = $basePath;

        $command = new AtlasLoopRollingWindowCli(
            $emitter,
            new AtlasLoopRollingWindowAggregator,
            new AtlasLoopRollingWindowComparator,
            new AtlasLoopRollingWindowReceiptLedger($basePath, fn (): string => '2026-06-24T15:00:00+00:00'),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-24T13:50:00+00:00', new DateTimeZone('UTC'))
        );

        $command->setLaravel($this->app);

        return $command;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->deleteTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
