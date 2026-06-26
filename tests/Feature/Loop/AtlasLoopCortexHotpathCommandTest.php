<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexHotpathCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathThresholdEmitter;
use Illuminate\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Locks the contract of AtlasLoopCortexHotpathCommand:
 *   atlas:loop:cortex:hotpath report  --window=N [--limit=L] [--json]
 *   atlas:loop:cortex:hotpath emit    --window=N --threshold=T [--limit=L] [--json]
 *
 * Surfaces FACTs only. No score, no verdict, no mutation.
 * Anti-Goodhart guard: refuses to emit rows with aggregate scalars.
 */
final class AtlasLoopCortexHotpathCommandTest extends TestCase
{
    private array $fixture = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = [
            ['cycle_id' => 'c1', 'touched_fqcns' => ['App\\A', 'App\\B']],
            ['cycle_id' => 'c2', 'touched_fqcns' => ['App\\A']],
            ['cycle_id' => 'c3', 'touched_fqcns' => ['App\\A', 'App\\C']],
            ['cycle_id' => 'c4', 'touched_fqcns' => ['App\\B']],
        ];
        app()->instance(
            AtlasLoopCortexHotpathCommand::CYCLE_FACTS_SOURCE_KEY,
            fn (int $w) => array_slice($this->fixture, -$w)
        );
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:hotpath', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_report_subcommand_emits_reporter_shape(): void
    {
        $r = $this->runCmd(['action' => 'report', '--window' => 4, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        $expected = (new AtlasCortexHotpathFrequencyReporter())->report($this->fixture);
        self::assertSame($expected, $payload);
    }

    public function test_emit_subcommand_emits_emitter_shape_with_threshold_used(): void
    {
        $r = $this->runCmd([
            'action' => 'emit',
            '--window' => 4,
            '--threshold' => 0.5,
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        $rows = (new AtlasCortexHotpathFrequencyReporter())->report($this->fixture);
        $expected = (new AtlasCortexHotpathThresholdEmitter())->emit($rows, 0.5);
        self::assertSame($expected, $payload);
        foreach ($payload as $row) {
            self::assertSame(0.5, $row['threshold_used']);
        }
    }

    public function test_unknown_action_exits_two(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertSame(2, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_threshold_out_of_range_exits_two_with_message(): void
    {
        $r = $this->runCmd([
            'action' => 'emit',
            '--window' => 4,
            '--threshold' => 1.5,
            '--json' => true,
        ]);
        self::assertSame(2, $r['exit']);
        self::assertStringContainsString('threshold_out_of_range', $r['output']);
    }

    public function test_negative_threshold_exits_two(): void
    {
        $r = $this->runCmd([
            'action' => 'emit',
            '--window' => 4,
            '--threshold' => -0.1,
            '--json' => true,
        ]);
        self::assertSame(2, $r['exit']);
    }

    public function test_non_json_output_is_plain_ascii_with_no_ansi_color_codes(): void
    {
        $r = $this->runCmd(['action' => 'report', '--window' => 4]);
        self::assertSame(0, $r['exit']);
        self::assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*m/', $r['output']);
    }

    public function test_limit_truncates_displayed_rows(): void
    {
        $r = $this->runCmd(['action' => 'report', '--window' => 4, '--limit' => 1, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertCount(1, $payload);
    }

    public function test_strict_master_with_master_disabled_exits_zero_with_status_disabled(): void
    {
        putenv('ATLAS_LOOP_MASTER_ENABLED=false');
        try {
            $r = $this->runCmd(['action' => 'report', '--window' => 4, '--json' => true, '--strict-master' => true]);
            self::assertSame(0, $r['exit']);
            $payload = json_decode(trim($r['output']), true);
            self::assertSame('disabled', $payload['status']);
        } finally {
            putenv('ATLAS_LOOP_MASTER_ENABLED');
        }
    }

    public function test_without_strict_master_runs_even_when_master_disabled(): void
    {
        putenv('ATLAS_LOOP_MASTER_ENABLED=false');
        try {
            $r = $this->runCmd(['action' => 'report', '--window' => 4, '--json' => true]);
            self::assertSame(0, $r['exit']);
            $payload = json_decode(trim($r['output']), true);
            self::assertIsArray($payload);
        } finally {
            putenv('ATLAS_LOOP_MASTER_ENABLED');
        }
    }

    public function test_goodhart_violation_exits_one_with_canonical_message(): void
    {
        // The guardGoodhart() is invoked inside handle() which routes
        // through the CLI; the per-row guard is unit-tested below.
        $this->markTestSkipped('Goodhart CLI flow tested via end-to-end integration; unit test below covers the guard.');
    }

    public function test_goodhart_guard_passes_clean_rows(): void
    {
        $cmd = app(AtlasLoopCortexHotpathCommand::class);
        $ref = new \ReflectionMethod($cmd, 'guardGoodhart');
        $ref->setAccessible(true);

        $rows = [[
            'fqcn' => 'App\\A',
            'cycle_count' => 4,
            'window_size' => 4,
            'frequency' => 1.0,
        ]];
        $ref->invoke($cmd, $rows);
        // No exception = pass
        self::assertTrue(true);
    }

    public function test_goodhart_guard_throws_for_aggregate_scalar_row(): void
    {
        $cmd = app(AtlasLoopCortexHotpathCommand::class);
        $ref = new \ReflectionMethod($cmd, 'guardGoodhart');
        $ref->setAccessible(true);

        $rows = [[
            'fqcn' => 'App\\A',
            'cycle_count' => 4,
            'window_size' => 4,
            'frequency' => 1.0,
            'score' => 99.9, // FORBIDDEN aggregate scalar
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('goodhart_violation');
        $ref->invoke($cmd, $rows);
    }

    public function test_command_makes_zero_writes_to_storage_across_all_actions(): void
    {
        $before = $this->snapshotStorage();
        $this->runCmd(['action' => 'report', '--window' => 4, '--json' => true]);
        $this->runCmd(['action' => 'emit', '--window' => 4, '--threshold' => 0.5, '--json' => true]);
        $after = $this->snapshotStorage();
        self::assertSame($before, $after);
    }

    /**
     * @return array<string,string>
     */
    private function snapshotStorage(): array
    {
        $root = storage_path('app');
        if (! is_dir($root)) {
            return [];
        }
        $map = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile()) {
                $map[$info->getPathname()] = (string) hash_file('sha256', $info->getPathname());
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}