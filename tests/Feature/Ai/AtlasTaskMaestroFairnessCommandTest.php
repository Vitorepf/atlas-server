<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessAlertEmitter;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessGiniReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroFairnessCommandTest extends TestCase
{
    private string $windowPath = '';

    private string $alertsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->windowPath = sys_get_temp_dir().'/atlas-fairness-cli-window-'.$tag.'.json';
        $this->alertsPath = sys_get_temp_dir().'/atlas-fairness-cli-alerts-'.$tag.'.jsonl';

        // Reporter: stub probe + completed task source.
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['lease_id' => 'l1', 'agent_id' => 'worker-a'],
            ['lease_id' => 'l2', 'agent_id' => 'worker-b'],
        ]);
        $completed = static fn (): array => [
            ['task_packet_id' => 'refactor-1', 'agent_id' => 'worker-a', 'outcome' => 'success'],
            ['task_packet_id' => 'docs-1', 'agent_id' => 'worker-b', 'outcome' => 'success'],
        ];
        $reporter = new AtlasMaestroFairnessGiniReporter($probe, $completed);
        app()->instance(AtlasMaestroFairnessGiniReporter::class, $reporter);
    }

    protected function tearDown(): void
    {
        @unlink($this->windowPath);
        @unlink($this->alertsPath);
        parent::tearDown();
    }

    private function bindEmitter(array $giniSequence, int $windowSize = 5, float $threshold = 0.6): void
    {
        $reporter = new class($giniSequence)
        {
            private int $cursor = 0;

            public function __construct(private array $giniSequence) {}

            public function report(): array
            {
                $g = $this->giniSequence[$this->cursor] ?? 0.0;
                $this->cursor++;

                return [
                    'gini_workers' => $g,
                    'gini_task_classes' => 0.1,
                    'max_worker_share_id' => 'worker-a',
                    'max_task_class_share_id' => 'refactor',
                ];
            }
        };
        $emitter = new AtlasMaestroFairnessAlertEmitter($reporter, $this->windowPath, $this->alertsPath, windowSize: $windowSize, threshold: $threshold);
        app()->instance(AtlasMaestroFairnessAlertEmitter::class, $emitter);
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:fairness', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_gini_json_contains_required_keys(): void
    {
        $r = $this->runCmd(['action' => 'gini', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        foreach (['gini_workers', 'gini_task_classes', 'worker_share_histogram', 'task_class_share_histogram'] as $key) {
            self::assertArrayHasKey($key, $payload, "missing {$key}");
        }
    }

    public function test_alerts_five_consecutive_breaches_emits_alert_only_on_fifth(): void
    {
        $this->bindEmitter([0.8, 0.8, 0.8, 0.8, 0.8], windowSize: 5, threshold: 0.6);

        for ($i = 1; $i <= 4; $i++) {
            $r = $this->runCmd(['action' => 'alerts']);
            self::assertSame(0, $r['exit']);
            self::assertStringContainsString('no alert', $r['output'], "call {$i} must be 'no alert', got: ".$r['output']);
        }

        $fifth = $this->runCmd(['action' => 'alerts']);
        self::assertSame(0, $fifth['exit']);
        self::assertStringContainsString('maestro_fairness_alert', $fifth['output']);
    }

    public function test_history_reads_seeded_alerts_in_append_order(): void
    {
        $this->bindEmitter([], 5, 0.6);
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->alertsPath, json_encode(['observed_at' => '2026-06-25T00:00:0'.$i.'Z', 'axis' => 'workers', 'gini_observed' => 0.7 + $i / 100])."\n", FILE_APPEND);
        }

        $r = $this->runCmd(['action' => 'history', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertCount(3, $payload);
        self::assertSame('2026-06-25T00:00:01Z', $payload[0]['observed_at']);
        self::assertSame('2026-06-25T00:00:03Z', $payload[2]['observed_at']);
    }

    public function test_unknown_action_exits_non_zero_with_usage_message(): void
    {
        $this->bindEmitter([], 5, 0.6);
        $r = $this->runCmd(['action' => 'reset']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
