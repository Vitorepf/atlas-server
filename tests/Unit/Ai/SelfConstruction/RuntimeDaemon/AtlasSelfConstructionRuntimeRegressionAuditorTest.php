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
}
