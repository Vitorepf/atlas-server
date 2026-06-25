<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexHotpathCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathHistoryReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathThresholdEmitter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCortexHotpathCommandTest extends TestCase
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
        app()->instance(AtlasLoopCortexHotpathCommand::CYCLE_FACTS_SOURCE_KEY, fn (int $w) => array_slice($this->fixture, -$w));
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:hotpath', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_frequency_subcommand_emits_reporter_shape(): void
    {
        $r = $this->runCmd(['action' => 'frequency', '--window' => 4, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        $expected = (new AtlasCortexHotpathFrequencyReporter())->report($this->fixture);
        self::assertSame($expected, $payload);
    }

    public function test_threshold_subcommand_emits_emitter_shape_with_threshold_used(): void
    {
        $r = $this->runCmd([
            'action' => 'threshold',
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

    public function test_history_subcommand_emits_per_fqcn_zero_one_vector_oldest_first(): void
    {
        $r = $this->runCmd(['action' => 'history', '--window' => 4, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        $expected = (new AtlasCortexHotpathHistoryReporter())->report($this->fixture);
        self::assertSame($expected, $payload);
        foreach ($payload as $row) {
            self::assertCount(4, $row['appearance_vector']);
            foreach ($row['appearance_vector'] as $v) {
                self::assertContains($v, [0, 1]);
            }
        }
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_threshold_out_of_range_exits_non_zero_with_message(): void
    {
        $r = $this->runCmd([
            'action' => 'threshold',
            '--window' => 4,
            '--threshold' => 1.5,
            '--json' => true,
        ]);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('threshold_out_of_range', $r['output']);
    }

    public function test_non_json_output_is_plain_ascii_with_no_ansi_color_codes(): void
    {
        $r = $this->runCmd(['action' => 'frequency', '--window' => 4]);
        self::assertSame(0, $r['exit']);
        self::assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*m/', $r['output']);
    }

    public function test_command_makes_zero_writes_to_storage_across_all_actions(): void
    {
        $before = $this->snapshotStorage();
        $this->runCmd(['action' => 'frequency', '--window' => 4, '--json' => true]);
        $this->runCmd(['action' => 'threshold', '--window' => 4, '--threshold' => 0.5, '--json' => true]);
        $this->runCmd(['action' => 'history', '--window' => 4, '--json' => true]);
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
