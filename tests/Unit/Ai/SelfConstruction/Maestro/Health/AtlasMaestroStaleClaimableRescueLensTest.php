<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroStaleClaimableRescueLens;
use Tests\TestCase;

final class AtlasMaestroStaleClaimableRescueLensTest extends TestCase
{
    private function svc(): AtlasMaestroStaleClaimableRescueLens
    {
        return new AtlasMaestroStaleClaimableRescueLens;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->diagnose([]);

        foreach (['diagnosis', 'severity', 'likely_cause', 'rescue_actions', 'evidence'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::SCHEMA, $r['schema']);
    }

    // ── AC1: healthy backlog depth ───────────────────────────────────────────────

    public function test_healthy_shallow_fresh_backlog_with_consumption_is_healthy(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 3,
            'oldest_age_p95_seconds' => 60,
            'observed_consumption_count' => 5,
            'active_workers' => 2,
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_HEALTHY_BACKLOG_DEPTH, $r['diagnosis']);
        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::SEVERITY_NONE, $r['severity']);
        $this->assertSame([], $r['rescue_actions']);
    }

    // ── AC2: deep + stale p95 + no observed consumption → stale_claimable_backlog_needs_rotation, never dry_queue ──

    public function test_deep_stale_no_consumption_is_classified_stale_backlog_rotation_not_dry_queue(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 50,
            'oldest_age_p95_seconds' => 7200,
            'stale_threshold_seconds' => 3600,
            'observed_consumption_count' => 0,
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_STALE_CLAIMABLE_BACKLOG_NEEDS_ROTATION, $r['diagnosis']);
        $this->assertNotSame('dry_queue', $r['diagnosis']);
        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::SEVERITY_HIGH, $r['severity']);
        $this->assertNotEmpty($r['rescue_actions']);
    }

    public function test_deep_but_fresh_backlog_is_not_stale_rotation(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 50,
            'oldest_age_p95_seconds' => 100,
            'stale_threshold_seconds' => 3600,
            'observed_consumption_count' => 0,
        ]);

        $this->assertNotSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_STALE_CLAIMABLE_BACKLOG_NEEDS_ROTATION, $r['diagnosis']);
    }

    // ── AC3: separates suspected stuck leases from stale claimable backlog ─────

    public function test_suspected_stuck_leases_is_separated_from_stale_backlog_diagnosis(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 50,
            'oldest_age_p95_seconds' => 7200,
            'stale_threshold_seconds' => 3600,
            'observed_consumption_count' => 0,
            'suspected_stuck_lease_count' => 4,
        ]);

        // Both signals present — stuck leases is the more specific, higher-priority finding.
        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_SUSPECTED_STUCK_LEASES, $r['diagnosis']);
        $this->assertNotSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_STALE_CLAIMABLE_BACKLOG_NEEDS_ROTATION, $r['diagnosis']);
    }

    public function test_does_not_blame_workers_when_active_workers_evidence_is_missing(): void
    {
        // No 'active_workers' key at all — must not produce low_worker_consumption.
        $r = $this->svc()->diagnose([
            'claimable_depth' => 10,
            'oldest_age_p95_seconds' => 100,
        ]);

        $this->assertNotSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_LOW_WORKER_CONSUMPTION, $r['diagnosis']);
    }

    public function test_blames_workers_only_when_active_workers_is_explicitly_zero(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 10,
            'oldest_age_p95_seconds' => 100,
            'observed_consumption_count' => 1,
            'active_workers' => 0,
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_LOW_WORKER_CONSUMPTION, $r['diagnosis']);
    }

    // ── task-family avoidance ────────────────────────────────────────────────────

    public function test_avoided_family_signals_produce_task_family_avoidance_diagnosis(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 5,
            'oldest_age_p95_seconds' => 100,
            'avoided_family_signals' => ['hard_refactor_class'],
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_TASK_FAMILY_AVOIDANCE, $r['diagnosis']);
        $this->assertStringContainsString('hard_refactor_class', $r['likely_cause']);
    }

    // ── near-expiry lease pressure ───────────────────────────────────────────────

    public function test_near_expiry_lease_count_produces_lease_pressure_diagnosis(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 5,
            'oldest_age_p95_seconds' => 100,
            'near_expiry_lease_count' => 3,
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_NEAR_EXPIRY_LEASE_PRESSURE, $r['diagnosis']);
    }

    // ── insufficient evidence ────────────────────────────────────────────────────

    public function test_missing_consumption_evidence_with_no_other_signals_is_insufficient_evidence(): void
    {
        $r = $this->svc()->diagnose([
            'claimable_depth' => 5,
            'oldest_age_p95_seconds' => 100,
        ]);

        $this->assertSame(AtlasMaestroStaleClaimableRescueLens::DIAGNOSIS_INSUFFICIENT_EVIDENCE, $r['diagnosis']);
    }

    // ── AC4: pure, read-only — output has no side effects ───────────────────────

    public function test_diagnose_does_not_touch_the_filesystem(): void
    {
        $dir = sys_get_temp_dir();
        $countBefore = count(scandir($dir) ?: []);

        $this->svc()->diagnose(['claimable_depth' => 5]);

        $countAfter = count(scandir($dir) ?: []);
        $this->assertSame($countBefore, $countAfter);
    }

    // ── evidence list is non-empty for every diagnosis ──────────────────────────

    public function test_evidence_is_never_empty(): void
    {
        $scenarios = [
            [],
            ['claimable_depth' => 50, 'oldest_age_p95_seconds' => 7200, 'observed_consumption_count' => 0],
            ['suspected_stuck_lease_count' => 1],
            ['avoided_family_signals' => ['x']],
            ['near_expiry_lease_count' => 1],
        ];

        foreach ($scenarios as $facts) {
            $r = $this->svc()->diagnose($facts);
            $this->assertNotEmpty($r['evidence'], 'evidence must never be empty: '.json_encode($facts));
        }
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_diagnose_is_deterministic(): void
    {
        $facts = ['claimable_depth' => 50, 'oldest_age_p95_seconds' => 7200, 'observed_consumption_count' => 0];

        $a = $this->svc()->diagnose($facts);
        $b = $this->svc()->diagnose($facts);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
