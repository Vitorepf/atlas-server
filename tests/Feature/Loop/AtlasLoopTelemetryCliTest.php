<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopTelemetryCli;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactExporter;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactStreamEmitter;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactWindowAggregator;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class AtlasLoopTelemetryCliTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_tail_prints_seeded_facts(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['action' => 'tail']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2026-06-24T00:40:00+00:00 claim cycle-1', $tester->getDisplay());
        $this->assertStringContainsString('2026-06-24T00:41:00+00:00 serve cycle-1', $tester->getDisplay());
    }

    public function test_aggregate_json_contains_only_counts_and_durations(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute([
            'action' => 'aggregate',
            '--minutes' => 30,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['counts', 'durations_ms'], array_keys($payload));
        $this->assertSame(['claim' => 1, 'lease' => 1, 'serve' => 1, 'report' => 1, 'merge' => 0], $payload['counts']);
        $this->assertArrayHasKey('claim_to_lease', $payload['durations_ms']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(score|rank|grade|quality|judgement)[^"]*"\s*:/i', $encoded);
    }

    public function test_export_appends_seeded_facts_to_jsonl_path(): void
    {
        $path = $this->tmpPath('export');
        $tester = new CommandTester($this->command());

        $exit = $tester->execute([
            'action' => 'export',
            '--path' => $path,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($path, $payload['path']);
        $this->assertSame(4, $payload['lines_written']);

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(4, $lines);
        $this->assertSame('claim', json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['kind']);
        $this->assertSame('report', json_decode($lines[3], true, flags: JSON_THROW_ON_ERROR)['kind']);
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $path = $this->tmpPath('unknown');
        $tester = new CommandTester($this->command());

        $exit = $tester->execute([
            'action' => 'mystery',
            '--path' => $path,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Unknown action: mystery', $tester->getDisplay());
        $this->assertFileDoesNotExist($path);
    }

    public function test_starvation_claim_without_serve_is_starved(): void
    {
        $tester = new CommandTester($this->starvationCommand([['claim', 'cycle-1']]));

        $exit = $tester->execute(['action' => 'starvation', '--minutes' => 15, '--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['starved'], 'a claim with no paired serve in the window is a stall');
        $this->assertSame(1, $payload['counts']['claim']);
        $this->assertArrayHasKey('window', $payload);
        $this->assertArrayHasKey('lease', $payload['counts']);
        $this->assertArrayHasKey('serve', $payload['counts']);
        $this->assertArrayHasKey('paired_claim_to_serve', $payload['evidence']);
    }

    public function test_starvation_paired_claim_and_serve_is_not_starved(): void
    {
        $tester = new CommandTester($this->starvationCommand([['claim', 'cycle-1'], ['serve', 'cycle-1']]));

        $exit = $tester->execute(['action' => 'starvation', '--minutes' => 15, '--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['starved'], 'a paired claim→serve in the window is healthy');
    }

    /**
     * @param  list<array{0:string,1:string}>  $emits  list of [kind, cycle_id] emitted at ~now (inside the window)
     */
    private function starvationCommand(array $emits): AtlasLoopTelemetryCli
    {
        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            null,
            static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        foreach ($emits as [$kind, $cycle]) {
            $emitter->emit($kind, ['scope' => 'loop', 'value' => $kind], $cycle);
        }

        $command = new AtlasLoopTelemetryCli(
            $emitter,
            new AtlasLoopTelemetryFactWindowAggregator,
            new AtlasLoopTelemetryFactExporter,
        );
        $command->setLaravel($this->app);

        return $command;
    }

    private function command(): AtlasLoopTelemetryCli
    {
        $ticks = [
            '2026-06-24T00:40:00+00:00',
            '2026-06-24T00:40:20+00:00',
            '2026-06-24T00:41:00+00:00',
            '2026-06-24T00:41:30+00:00',
        ];
        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            null,
            static function () use (&$ticks): DateTimeImmutable {
                $value = array_shift($ticks) ?? '2026-06-24T00:41:30+00:00';

                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            }
        );
        $emitter->emit('claim', ['scope' => 'loop', 'value' => 'claim'], 'cycle-1');
        $emitter->emit('lease', ['scope' => 'loop', 'value' => 'lease'], 'cycle-1');
        $emitter->emit('serve', ['scope' => 'loop', 'value' => 'serve'], 'cycle-1');
        $emitter->emit('report', ['scope' => 'loop', 'value' => 'report'], 'cycle-2');

        $command = new AtlasLoopTelemetryCli(
            $emitter,
            new AtlasLoopTelemetryFactWindowAggregator,
            new AtlasLoopTelemetryFactExporter,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-24T01:00:00+00:00', new DateTimeZone('UTC'))
        );

        $command->setLaravel($this->app);

        return $command;
    }

    private function tmpPath(string $slug): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-telemetry-cli-'.$slug.'-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->paths[] = $path;

        return $path;
    }
}
