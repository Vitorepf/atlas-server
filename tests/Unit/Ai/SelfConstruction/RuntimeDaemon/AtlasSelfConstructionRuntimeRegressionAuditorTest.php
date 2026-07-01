<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeRegressionAuditor;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakRunner;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakScenarioBuilder;
use Tests\TestCase;

class AtlasSelfConstructionRuntimeRegressionAuditorTest extends TestCase
{
    private function cleanSoakReport(): array
    {
        $scenario = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build(['max_ticks' => 10]);

        return (new AtlasSelfConstructionRuntimeSoakRunner)->run($scenario);
    }

    public function test_complete_soak_with_evidence_refs_passes(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            ['evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt']],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertSame([], $verdict['blockers']);
    }

    public function test_fake_green_with_zero_ticks_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            ['passed' => true, 'tick_count' => 0, 'tick_results' => [], 'dependency_violations' => []],
            ['evidence_refs' => ['tests_or_gates_result']],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_BLOCKED, $verdict['verdict']);
        self::assertContains('fake_green:tick_count_zero', $verdict['blockers']);
    }

    public function test_success_without_evidence_refs_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            ['evidence_refs' => []],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_BLOCKED, $verdict['verdict']);
        self::assertContains('fake_green:success_without_evidence_refs', $verdict['blockers']);
    }

    public function test_dependency_regression_is_blocked(): void
    {
        $soak = $this->cleanSoakReport();
        $soak['passed'] = false;
        $soak['dependency_violations'] = [['tick_kind' => 'green_cycle', 'violation' => 'requires_operator']];

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $soak,
            ['evidence_refs' => ['tests_or_gates_result']],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_BLOCKED, $verdict['verdict']);
        self::assertContains('dependency_regression:requires_operator@green_cycle', $verdict['blockers']);
    }

    public function test_missing_required_case_blocks(): void
    {
        $soak = [
            'passed' => true,
            'tick_count' => 1,
            'tick_results' => [['index' => 0, 'kind' => 'green_cycle', 'classification' => 'green']],
            'dependency_violations' => [],
        ];

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit($soak, ['evidence_refs' => ['x']]);

        self::assertNotSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertContains('missing_required_case:safety_stop', $verdict['blockers']);
    }

    public function test_recovery_case_not_recovered_blocks(): void
    {
        $soak = $this->cleanSoakReport();
        foreach ($soak['tick_results'] as $i => $row) {
            if ($row['kind'] === 'stale_heartbeat_recovery') {
                $soak['tick_results'][$i]['classification'] = 'held'; // pretend recovery did not happen
                break;
            }
        }

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $soak,
            ['evidence_refs' => ['tests_or_gates_result']],
        );

        self::assertContains('recovery_case_did_not_recover:stale_heartbeat_recovery', $verdict['blockers']);
    }

    public function test_warnings_for_soft_signals_yield_hold(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs' => ['tests_or_gates_result'],
                'scheduler_manifest_match' => false,
                'heartbeat_continuity_breaks' => 1,
            ],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_HOLD, $verdict['verdict']);
        self::assertNotEmpty($verdict['warnings']);
    }

    public function test_audit_hash_is_deterministic(): void
    {
        $auditor = new AtlasSelfConstructionRuntimeRegressionAuditor();
        $soak = $this->cleanSoakReport();
        $a = $auditor->audit($soak, ['evidence_refs' => ['x']]);
        $b = $auditor->audit($soak, ['evidence_refs' => ['x']]);

        self::assertSame($a['regression_audit_hash'], $b['regression_audit_hash']);
    }

    public function test_duplicate_required_case_without_acknowledged_repeat_prevents_pass(): void
    {
        $soak = [
            'passed' => true,
            'tick_count' => 2,
            'tick_results' => [
                ['index' => 0, 'kind' => 'green_cycle', 'classification' => 'green'],
                ['index' => 1, 'kind' => 'green_cycle', 'classification' => 'green'], // duplicate
            ],
            'dependency_violations' => [],
        ];

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $soak,
            ['evidence_refs' => ['runtime', 'recovery']],
        );

        self::assertNotSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertContains('duplicate_required_case_unacknowledged:green_cycle', $verdict['blockers']);
    }

    public function test_acknowledged_repeat_removes_duplicate_blocker(): void
    {
        $soak = [
            'passed' => true,
            'tick_count' => 2,
            'tick_results' => [
                ['index' => 0, 'kind' => 'green_cycle', 'classification' => 'green'],
                ['index' => 1, 'kind' => 'green_cycle', 'classification' => 'green'],
            ],
            'dependency_violations' => [],
        ];

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $soak,
            ['evidence_refs' => ['runtime', 'recovery'], 'acknowledged_repeats' => ['green_cycle']],
        );

        self::assertNotContains('duplicate_required_case_unacknowledged:green_cycle', $verdict['blockers']);
    }

    public function test_stale_evidence_timestamp_prevents_pass(): void
    {
        $nowUnix = 1_700_100_000;
        $staleTs = $nowUnix - 7_200; // 2 hours old, max allowed = 3600s

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs'           => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
                'evidence_timestamps'     => [$staleTs],
                'max_evidence_age_seconds' => 3_600,
                'now_unix'                => $nowUnix,
            ],
        );

        self::assertNotSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertContains('stale_evidence:max_age=3600s_exceeded', $verdict['blockers']);
    }

    public function test_fresh_evidence_timestamps_are_accepted(): void
    {
        $nowUnix = 1_700_100_000;
        $freshTs = $nowUnix - 600; // 10 minutes old, max allowed = 3600s

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs'           => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
                'evidence_timestamps'     => [$freshTs],
                'max_evidence_age_seconds' => 3_600,
                'now_unix'                => $nowUnix,
            ],
        );

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertNotContains('stale_evidence:max_age=3600s_exceeded', $verdict['blockers']);
    }

    public function test_missing_recovery_evidence_with_one_ref_prevents_pass(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            ['evidence_refs' => ['tests_or_gates_result']], // only 1 ref, recovery exercised
        );

        self::assertNotSame(AtlasSelfConstructionRuntimeRegressionAuditor::VERDICT_PASS, $verdict['verdict']);
        self::assertContains('missing_recovery_evidence:insufficient_refs_for_recovery_proof', $verdict['blockers']);
    }

    public function test_stale_evidence_hash_is_still_deterministic(): void
    {
        $nowUnix = 1_700_100_000;
        $facts = [
            'evidence_refs'           => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
            'evidence_timestamps'     => [$nowUnix - 7_200],
            'max_evidence_age_seconds' => 3_600,
            'now_unix'                => $nowUnix,
        ];
        $soak = $this->cleanSoakReport();
        $a = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit($soak, $facts);
        $b = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit($soak, $facts);

        self::assertSame($a['regression_audit_hash'], $b['regression_audit_hash']);
    }

    // ── AC1/AC2: structured regressions carry severity, evidence_ref, required_repair, promotion_blocked ──

    public function test_clean_soak_has_no_regressions_and_promotion_not_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            ['evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt']],
        );

        self::assertSame([], $verdict['regressions']);
        self::assertFalse($verdict['promotion_blocked']);
    }

    public function test_stale_proof_produces_proof_freshness_regression_with_full_fields(): void
    {
        $nowUnix = 1_700_100_000;
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
                'evidence_timestamps' => [$nowUnix - 7_200],
                'max_evidence_age_seconds' => 3_600,
                'now_unix' => $nowUnix,
            ],
        );

        $regression = array_values(array_filter(
            $verdict['regressions'],
            static fn (array $r): bool => $r['type'] === AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_PROOF_FRESHNESS,
        ))[0];

        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::SEVERITY_HIGH, $regression['severity']);
        self::assertArrayHasKey('evidence_ref', $regression);
        self::assertNotEmpty($regression['required_repair']);
        self::assertTrue($regression['promotion_blocked']);
        self::assertTrue($verdict['promotion_blocked']);
    }

    public function test_worker_outcome_regression_when_failure_rate_exceeds_threshold(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
                'worker_outcomes' => ['failed_task_count' => 5, 'total_task_count' => 10],
            ],
        );

        $types = array_column($verdict['regressions'], 'type');
        self::assertContains(AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_WORKER_OUTCOMES, $types);
        self::assertTrue($verdict['promotion_blocked']);
    }

    public function test_safety_stop_regression_when_tick_did_not_stop_safely(): void
    {
        $soak = $this->cleanSoakReport();
        $soak['tick_results'][] = ['kind' => 'safety_stop', 'classification' => 'did_not_stop'];

        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $soak,
            ['evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt']],
        );

        $types = array_column($verdict['regressions'], 'type');
        self::assertContains(AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_SAFETY_STOP, $types);
        $safetyRegression = array_values(array_filter(
            $verdict['regressions'],
            static fn (array $r): bool => $r['type'] === AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_SAFETY_STOP,
        ))[0];
        self::assertSame(AtlasSelfConstructionRuntimeRegressionAuditor::SEVERITY_CRITICAL, $safetyRegression['severity']);
        self::assertTrue($safetyRegression['promotion_blocked']);
    }

    public function test_missing_evidence_produces_proof_freshness_regression(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            ['evidence_refs' => []],
        );

        $types = array_column($verdict['regressions'], 'type');
        self::assertContains(AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_PROOF_FRESHNESS, $types);
        self::assertTrue($verdict['promotion_blocked']);
    }

    public function test_queue_health_and_learning_loop_continuity_regressions_are_hold_not_blocking(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeRegressionAuditor)->audit(
            $this->cleanSoakReport(),
            [
                'evidence_refs' => ['tests_or_gates_result', 'replenisher_dry_run_receipt'],
                'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 5],
                'learning_loop_continuity_breaks' => 2,
            ],
        );

        $types = array_column($verdict['regressions'], 'type');
        self::assertContains(AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_QUEUE_HEALTH, $types);
        self::assertContains(AtlasSelfConstructionRuntimeRegressionAuditor::REGRESSION_LEARNING_LOOP_CONTINUITY, $types);
        foreach ($verdict['regressions'] as $r) {
            self::assertFalse($r['promotion_blocked'], "regression '{$r['type']}' should not block promotion on its own");
        }
    }
}
