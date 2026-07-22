<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopHungGrindDetector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the hung-grind detector: BOTH conditions required for `hung` (swap-thrashing-slow stays
 * `slow_but_live`, never killed); the detector only inspects the probe snapshot and the caller's progress
 * oracle, NEVER spawns processes or talks to the network (source isolation check); unknown progress yields
 * slow_but_live, not hung (anti-Goodhart: "we don't know" ≠ "kill it").
 */
final class AtlasLoopHungGrindDetectorTest extends TestCase
{
    /**
     * @param  list<array<string,mixed>>  $processes
     * @return array<string,mixed>
     */
    private function snapshot(array $processes): array
    {
        return [
            'schema_version' => 'atlas.loop.process_topology.v1',
            'captured_at_ts' => '2026-06-24T00:00:00Z',
            'host' => 'test',
            'processes' => $processes,
            'tree' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function grind(int $pid, int $etimeS, float $cpu = 0.0, ?string $campaignId = 'camp-1', string $role = 'grind'): array
    {
        return [
            'pid' => $pid,
            'ppid' => 1,
            'etime_s' => $etimeS,
            'cpu' => $cpu,
            'mem' => 0.1,
            'role' => $role,
            'campaign_id_or_null' => $campaignId,
            'command' => 'php artisan atlas:loop:run-scenario --campaign-id='.($campaignId ?? 'none'),
        ];
    }

    public function test_marks_hung_only_when_both_etime_and_progress_age_exceed_thresholds(): void
    {
        $detector = new AtlasLoopHungGrindDetector(
            etimeThresholdSec: 900,
            progressAgeThresholdSec: 600,
            progressOracle: static fn (int $pid): ?int => match ($pid) {
                100 => 1200, // last progress 20min ago — exceeds 600s window
                default => 60,
            },
        );

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(100, etimeS: 1800), // long etime + stale progress ⇒ hung
        ]));

        $this->assertCount(1, $verdicts);
        $this->assertSame(AtlasLoopHungGrindDetector::VERDICT_HUNG, $verdicts[0]['verdict']);
        $this->assertSame(1800, $verdicts[0]['evidence']['etime_s']);
        $this->assertSame(1200, $verdicts[0]['evidence']['last_progress_age_s']);
    }

    public function test_swap_thrashing_slow_long_etime_but_recent_progress_is_slow_but_live_never_hung(): void
    {
        // The exact memory case (loop-babysit-pgrep-gotcha-and-hardening): long etime but RECENT heartbeat —
        // killing this is the failure mode the detector exists to prevent.
        $detector = new AtlasLoopHungGrindDetector(
            etimeThresholdSec: 900,
            progressAgeThresholdSec: 600,
            progressOracle: static fn (int $pid): ?int => 30, // heartbeat 30s ago — fresh
        );

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(200, etimeS: 7200), // 2h alive
        ]));

        $this->assertSame(AtlasLoopHungGrindDetector::VERDICT_SLOW_BUT_LIVE, $verdicts[0]['verdict']);
        $this->assertNotSame(AtlasLoopHungGrindDetector::VERDICT_HUNG, $verdicts[0]['verdict'], 'swap-thrashing-slow MUST never be marked hung');
    }

    public function test_unknown_progress_yields_slow_but_live_not_hung(): void
    {
        $detector = new AtlasLoopHungGrindDetector(
            etimeThresholdSec: 900,
            progressAgeThresholdSec: 600,
            progressOracle: static fn (int $pid): ?int => null, // oracle has no row for this pid
        );

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(300, etimeS: 3600),
        ]));

        $this->assertSame(AtlasLoopHungGrindDetector::VERDICT_SLOW_BUT_LIVE, $verdicts[0]['verdict'], '"we do not know" is not enough evidence to call hung');
        $this->assertNull($verdicts[0]['evidence']['last_progress_age_s']);
    }

    public function test_short_etime_grind_is_healthy_regardless_of_progress(): void
    {
        $detector = new AtlasLoopHungGrindDetector(
            etimeThresholdSec: 900,
            progressAgeThresholdSec: 600,
            progressOracle: static fn (int $pid): ?int => 99999, // ancient ⇒ would be stale, but etime is short
        );

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(400, etimeS: 60),
        ]));

        $this->assertSame(AtlasLoopHungGrindDetector::VERDICT_HEALTHY, $verdicts[0]['verdict']);
    }

    public function test_filters_to_grind_and_hermes_roles_only(): void
    {
        $detector = new AtlasLoopHungGrindDetector(progressOracle: static fn (): ?int => null);

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(500, etimeS: 100, role: 'supervisor'),
            $this->grind(501, etimeS: 100, role: 'watchdog'),
            $this->grind(502, etimeS: 100, role: 'keepalive'),
            $this->grind(503, etimeS: 100, role: 'grind'),
            $this->grind(504, etimeS: 100, role: 'hermes'),
        ]));

        $pids = array_column($verdicts, 'pid');
        $this->assertSame([503, 504], $pids, 'only grind+hermes roles are evaluated; supervisor/watchdog/keepalive are outside scope');
    }

    public function test_detector_source_has_no_process_spawn_or_network_call(): void
    {
        $reflection = new ReflectionClass(AtlasLoopHungGrindDetector::class);
        $this->assertTrue($reflection->isFinal(), 'detector must be final');

        $source = (string) file_get_contents($reflection->getFileName());
        foreach (['shell_exec', 'exec(', 'proc_open', 'popen(', 'passthru', 'system(', 'curl_exec', 'Http::', '\\Http\\', 'Hermes', 'file_get_contents(\'http', 'fsockopen', 'fopen(\'http'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "detector must NOT use $banned (process spawn or network)");
        }
    }

    public function test_evidence_vector_includes_etimes_last_progress_age_and_cpu_avg(): void
    {
        $detector = new AtlasLoopHungGrindDetector(
            etimeThresholdSec: 900,
            progressAgeThresholdSec: 600,
            progressOracle: static fn (int $pid): ?int => 700,
        );

        $verdicts = $detector->detect($this->snapshot([
            $this->grind(600, etimeS: 1100, cpu: 12.5),
        ]));

        $this->assertArrayHasKey('etime_s', $verdicts[0]['evidence']);
        $this->assertArrayHasKey('last_progress_age_s', $verdicts[0]['evidence']);
        $this->assertArrayHasKey('cpu_avg', $verdicts[0]['evidence']);
        $this->assertSame(12.5, $verdicts[0]['evidence']['cpu_avg']);
    }
}
