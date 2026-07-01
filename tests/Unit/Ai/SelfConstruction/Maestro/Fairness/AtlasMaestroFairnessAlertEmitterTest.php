<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Fairness;

use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessAlertEmitter;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroFairnessAlertEmitterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-fairness-alert-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function reporter(array $report = []): object
    {
        return new class($report)
        {
            public function __construct(private array $r) {}

            public function report(): array
            {
                return $this->r;
            }
        };
    }

    private function emitter(): AtlasMaestroFairnessAlertEmitter
    {
        return new AtlasMaestroFairnessAlertEmitter(
            $this->reporter(),
            $this->dir.'/window.json',
            $this->dir.'/alerts.jsonl',
        );
    }

    public function test_starved_lane_emits_lane_starvation_alert(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'lanes' => [
                ['lane_id' => 'lane-idle', 'idle_ratio' => 0.9],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame('lane_starvation', $alerts[0]['reason_code']);
        $this->assertSame('lane-idle', $alerts[0]['lane_id']);
        $this->assertSame('lane', $alerts[0]['axis']);
    }

    public function test_lane_below_threshold_emits_no_alert(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'lanes' => [
                ['lane_id' => 'lane-fine', 'idle_ratio' => 0.1],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    public function test_over_served_worker_emits_worker_overconcentration_alert(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'workers' => [
                ['worker_id' => 'worker-hog', 'share' => 0.95],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame('worker_overconcentration', $alerts[0]['reason_code']);
        $this->assertSame('worker-hog', $alerts[0]['worker_id']);
        $this->assertSame('worker', $alerts[0]['axis']);
    }

    public function test_worker_below_threshold_emits_no_alert(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'workers' => [
                ['worker_id' => 'worker-fine', 'share' => 0.2],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    public function test_safety_critical_lane_is_exempted_from_throttling_but_still_reported(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'lanes' => [
                ['lane_id' => 'lane-safety', 'idle_ratio' => 0.95, 'safety_critical' => true],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame('lane_starvation', $alerts[0]['reason_code']);
        $this->assertTrue($alerts[0]['safety_exempt']);
        $this->assertFalse($alerts[0]['throttle_recommended']);
    }

    public function test_safety_critical_worker_is_exempted_from_throttling_but_still_reported(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'workers' => [
                ['worker_id' => 'worker-safety', 'share' => 0.95, 'safety_critical' => true],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertTrue($alerts[0]['safety_exempt']);
        $this->assertFalse($alerts[0]['throttle_recommended']);
    }

    public function test_non_safety_critical_breach_recommends_throttling(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'workers' => [
                ['worker_id' => 'worker-normal', 'share' => 0.95, 'safety_critical' => false],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertFalse($alerts[0]['safety_exempt']);
        $this->assertTrue($alerts[0]['throttle_recommended']);
    }

    public function test_alerts_are_persisted_to_alerts_file(): void
    {
        $this->emitter()->emitFairnessAlerts([
            'lanes' => [['lane_id' => 'lane-x', 'idle_ratio' => 0.9]],
        ]);

        $emitter2 = $this->emitter();
        $persisted = $emitter2->readAlerts();

        $this->assertNotEmpty($persisted);
        $this->assertSame('lane_starvation', $persisted[array_key_last($persisted)]['reason_code']);
    }

    public function test_mixed_lanes_and_workers_emit_both_alert_kinds(): void
    {
        $alerts = $this->emitter()->emitFairnessAlerts([
            'lanes' => [['lane_id' => 'lane-1', 'idle_ratio' => 0.9]],
            'workers' => [['worker_id' => 'worker-1', 'share' => 0.9]],
        ]);

        $this->assertCount(2, $alerts);
        $reasons = array_column($alerts, 'reason_code');
        $this->assertContains('lane_starvation', $reasons);
        $this->assertContains('worker_overconcentration', $reasons);
    }
}
