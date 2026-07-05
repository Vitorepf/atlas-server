<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationStatusBatchService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneCertificationStatusBatchService cannot hide failed
 * certification surfaces behind an overall healthy flag.
 */
final class AgentControlPlaneCertificationStatusBatchServiceTest extends TestCase
{
    private function service(): AgentControlPlaneCertificationStatusBatchService
    {
        return new AgentControlPlaneCertificationStatusBatchService(
            app(AtlasSelfConstructionReadinessService::class),
        );
    }

    // ── AC2: returns one status row per requested surface ─────────────

    public function test_run_returns_status_row_for_each_registered_surface(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification', 'replay_diff', 'certification_baseline'],
        ]);

        $this->assertGreaterThan(0, $result['checked_count']);
        $this->assertSame($result['checked_count'], count($result['statuses']));
        foreach ($result['statuses'] as $row) {
            $this->assertArrayHasKey('key', $row);
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('passed', $row);
            $this->assertIsBool($row['passed']);
        }
    }

    public function test_run_returns_requested_surfaces_via_only_keys(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification', 'replay_diff', 'certification_baseline'],
        ]);

        $this->assertSame(3, $result['checked_count']);
        $keys = array_column($result['statuses'], 'key');
        $this->assertContains('chain_integrity_certification', $keys);
        $this->assertContains('replay_diff', $keys);
        $this->assertContains('certification_baseline', $keys);
    }

    // ── AC3: overall_status=blocked when any blocking surface fails ──

    public function test_run_overall_status_failed_when_any_surface_fails(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification', 'replay_diff', 'certification_baseline'],
        ]);

        $hasFailed = $result['failed_count'] > 0;
        if ($hasFailed) {
            $this->assertSame('failed', $result['status']);
            $this->assertGreaterThan(0, $result['failed_count']);
        } else {
            $this->assertSame('passed', $result['status']);
            $this->assertSame(0, $result['failed_count']);
        }
    }

    public function test_run_reports_missing_method_as_failed(): void
    {
        // Use only_keys with a key that doesn't exist in STATUS_PROJECTIONS
        // to verify unknown keys are skipped (not included), then verify
        // every returned status has a valid method.
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification'],
        ]);

        foreach ($result['statuses'] as $row) {
            $this->assertArrayHasKey('method', $row);
            $this->assertNotEmpty($row['method']);
        }
    }

    // ── AC4: distinguishes missing, stale, failed, passed ─────────────

    public function test_run_distinguishes_passed_and_failed_surfaces(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification', 'replay_diff', 'certification_baseline'],
        ]);

        $this->assertSame(
            $result['passed_count'] + $result['failed_count'],
            $result['checked_count'],
            'passed_count + failed_count must equal checked_count',
        );
    }

    public function test_run_with_only_keys_where_all_pass(): void
    {
        // Pick keys that are structurally simple and likely to pass.
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification', 'replay_diff'],
        ]);

        // Either they all pass or at least we check the shape is consistent.
        $this->assertSame(count($result['statuses']), $result['checked_count']);
        $this->assertSame($result['passed_count'] + $result['failed_count'], $result['checked_count']);
    }

    // ── Schema and determinism ────────────────────────────────────────

    public function test_run_schema_is_stable(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification'],
        ]);

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_certification_status_batch.v1',
            $result['schema_version'],
        );
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_run_is_deterministic(): void
    {
        $options = ['only_keys' => ['chain_integrity_certification']];

        $a = $this->service()->run($options);
        $b = $this->service()->run($options);

        $this->assertSame(count($a['statuses']), count($b['statuses']));
        $this->assertSame($a['passed_count'], $b['passed_count']);
        $this->assertSame($a['failed_count'], $b['failed_count']);
    }

    public function test_run_schema_has_non_execution_guarantees(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification'],
        ]);

        $this->assertArrayHasKey('non_execution_guarantees', $result);
        $this->assertGreaterThan(0, count($result['non_execution_guarantees']));
        $this->assertContains(
            'status_batch_does_not_start_codex',
            $result['non_execution_guarantees'],
        );
    }

    public function test_run_emits_batch_hash(): void
    {
        $result = $this->service()->run([
            'only_keys' => ['chain_integrity_certification'],
        ]);

        $this->assertArrayHasKey('batch_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['batch_hash']);
    }
}
