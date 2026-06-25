<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessAlertEmitter;
use Tests\TestCase;

class AtlasMaestroFairnessAlertEmitterTest extends TestCase
{
    private string $windowPath = '';

    private string $alertsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $base = sys_get_temp_dir().'/atlas-fairness-alert-'.$tag;
        @mkdir($base, 0o755, true);
        $this->windowPath = $base.'/window.json';
        $this->alertsPath = $base.'/alerts.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->windowPath);
        @unlink($this->alertsPath);
        parent::tearDown();
    }

    private function stubReporter(array $giniWorkersSequence, array $giniTaskClassesSequence = []): object
    {
        return new class($giniWorkersSequence, $giniTaskClassesSequence)
        {
            private int $cursor = 0;

            public function __construct(private array $giniWorkersSequence, private array $giniTaskClassesSequence) {}

            public function report(): array
            {
                $workers = $this->giniWorkersSequence[$this->cursor] ?? 0.0;
                $taskClasses = $this->giniTaskClassesSequence[$this->cursor] ?? 0.0;
                $this->cursor++;

                return [
                    'gini_workers' => $workers,
                    'gini_task_classes' => $taskClasses,
                    'max_worker_share_id' => 'worker-A',
                    'max_task_class_share_id' => 'class-X',
                ];
            }
        };
    }

    public function test_five_consecutive_over_threshold_emits_exactly_one_workers_alert(): void
    {
        $reporter = $this->stubReporter([0.8, 0.8, 0.8, 0.8, 0.8]);
        $emitter = new AtlasMaestroFairnessAlertEmitter($reporter, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 1; $i <= 5; $i++) {
            $emitter->emit('2026-06-25T00:00:0'.$i.'Z');
        }
        $alerts = $emitter->readAlerts();

        self::assertCount(1, $alerts);
        self::assertSame('workers', $alerts[0]['axis']);
        self::assertSame(5, $alerts[0]['cycles_over']);
        self::assertSame('worker-A', $alerts[0]['max_share_id']);
    }

    public function test_alternating_sequence_never_n_consecutive_over_emits_zero_alerts(): void
    {
        $reporter = $this->stubReporter([0.8, 0.1, 0.8, 0.1, 0.8]);
        $emitter = new AtlasMaestroFairnessAlertEmitter($reporter, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 1; $i <= 5; $i++) {
            $emitter->emit('2026-06-25T00:00:0'.$i.'Z');
        }

        self::assertCount(0, $emitter->readAlerts());
        $window = $emitter->loadWindow();
        self::assertCount(5, $window);
        self::assertSame(0.8, $window[0]['gini_workers']);
        self::assertSame(0.8, $window[4]['gini_workers']);
    }

    public function test_emitter_does_not_throw_on_fresh_storage_and_resumes_from_persisted_window(): void
    {
        // No window.json exists yet → first call must not throw.
        $reporter = $this->stubReporter([0.8, 0.8, 0.8, 0.8, 0.8]);
        $emitter = new AtlasMaestroFairnessAlertEmitter($reporter, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        $emitter->emit('2026-06-25T00:00:01Z');
        self::assertFileExists($this->windowPath);

        // Simulate process restart: new emitter, fresh reporter; window count starts at 1 on disk.
        $reporter2 = $this->stubReporter([0.8, 0.8, 0.8, 0.8]);
        $emitter2 = new AtlasMaestroFairnessAlertEmitter($reporter2, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 2; $i <= 5; $i++) {
            $emitter2->emit('2026-06-25T00:00:0'.$i.'Z');
        }

        // After 1 + 4 = 5 consecutive over, exactly one alert must have been emitted.
        self::assertCount(1, $emitter2->readAlerts());
    }

    public function test_emitter_source_does_not_touch_queue_or_kill_workers(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Fairness/AtlasMaestroFairnessAlertEmitter.php'));
        foreach (['exec(', 'shell_exec', 'system(', 'proc_open', 'posix_kill', 'putenv(', "kill(", 'ATLAS_LOOP'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "emitter must not contain {$forbidden}");
        }
    }

    public function test_alerts_jsonl_is_append_only_across_three_sessions(): void
    {
        // Session 1: emit one workers alert.
        $reporter1 = $this->stubReporter([0.8, 0.8, 0.8, 0.8, 0.8]);
        $emitter1 = new AtlasMaestroFairnessAlertEmitter($reporter1, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 1; $i <= 5; $i++) {
            $emitter1->emit('2026-06-25T00:00:0'.$i.'Z');
        }
        $bytesAfterS1 = (string) file_get_contents($this->alertsPath);

        // Session 2: stay over for another 5 — should NOT emit a new alert (no transition).
        $reporter2 = $this->stubReporter([0.8, 0.8, 0.8, 0.8, 0.8]);
        $emitter2 = new AtlasMaestroFairnessAlertEmitter($reporter2, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 6; $i <= 10; $i++) {
            $emitter2->emit('2026-06-25T00:00:1'.($i - 6).'Z');
        }
        $bytesAfterS2 = (string) file_get_contents($this->alertsPath);
        self::assertSame($bytesAfterS1, $bytesAfterS2, 'alerts.jsonl must not be rewritten on subsequent sessions');

        // Session 3: drop and rise again to trigger a NEW transition. Drop 5 below, then 5 over.
        $reporter3 = $this->stubReporter([0.1, 0.1, 0.1, 0.1, 0.1, 0.8, 0.8, 0.8, 0.8, 0.8]);
        $emitter3 = new AtlasMaestroFairnessAlertEmitter($reporter3, $this->windowPath, $this->alertsPath, windowSize: 5, threshold: 0.6);
        for ($i = 0; $i < 10; $i++) {
            $emitter3->emit('2026-06-25T01:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'Z');
        }

        $bytesAfterS3 = (string) file_get_contents($this->alertsPath);
        self::assertSame($bytesAfterS2, substr($bytesAfterS3, 0, strlen($bytesAfterS2)), 'prior bytes unchanged');
        self::assertGreaterThan(strlen($bytesAfterS2), strlen($bytesAfterS3), 'session 3 should append at least one alert');
    }
}
