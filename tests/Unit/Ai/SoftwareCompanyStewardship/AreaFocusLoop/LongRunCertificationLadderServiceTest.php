<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DepartmentQualityBarThresholdContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongRunCertificationLadderService;
use Tests\TestCase;

final class LongRunCertificationLadderServiceTest extends TestCase
{
    private function service(): LongRunCertificationLadderService
    {
        return app(LongRunCertificationLadderService::class);
    }

    /**
     * Pull a single rung row out of the report by name.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function rung(array $report, string $name): array
    {
        foreach ($report['rungs'] as $r) {
            if ($r['rung'] === $name) {
                return $r;
            }
        }
        $this->fail("rung {$name} not present in report");
    }

    /**
     * Input seams proving everything up to (and including) the chaos rung, but
     * nothing real. Real / soak rungs are absent (unproven => pending).
     *
     * @return array<string,mixed>
     */
    private function simAndChaosProven(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'simulation_report' => ['simulated_cycles' => 1000, 'ok' => true],
            'chaos_report' => ['passed' => true],
            'invariant_report' => ['all_held' => true],
        ];
    }

    public function test_with_only_sim_passing_ten_real_is_blocked_with_precise_next_blocker(): void
    {
        // Only simulation proven; chaos/real absent. ten_real must be pending/blocked
        // and the ladder must report a precise next_blocker, never an `ok` status.
        $report = $this->service()->evaluate([
            'simulation_report' => ['simulated_cycles' => 1000, 'ok' => true],
        ]);

        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_TEN_SIMULATED)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_THOUSAND_SIMULATED)['status']);

        // ten_real never passed.
        $tenReal = $this->rung($report, LongRunCertificationLadderService::RUNG_TEN_REAL);
        $this->assertNotSame(LongRunCertificationLadderService::RUNG_PASSED, $tenReal['status']);

        // The ladder is stuck at chaos (the first non-passed rung) with a precise blocker.
        $this->assertSame(LongRunCertificationLadderService::RUNG_CHAOS, $report['current_rung']);
        $this->assertNotNull($report['next_blocker']);
        $this->assertSame('chaos_or_invariant_report_absent', $report['next_blocker']);

        // highest_passed reflects only the simulated rungs.
        $this->assertSame(LongRunCertificationLadderService::RUNG_THOUSAND_SIMULATED, $report['highest_passed']);

        // blocks summary: ten_real / 24h / 7d / 30d all not reached.
        $this->assertFalse($report['blocks'][LongRunCertificationLadderService::RUNG_TEN_REAL]);
        $this->assertFalse($report['blocks']['24h']);
        $this->assertFalse($report['blocks']['7d']);
        $this->assertFalse($report['blocks']['30d']);
    }

    public function test_chaos_plus_sim_plus_invariant_pass_unlocks_the_next_rung(): void
    {
        // With sim + chaos + invariants proven, the chaos rung passes and promotion
        // advances to one_real (the next rung) as the new current_rung.
        $report = $this->service()->evaluate($this->simAndChaosProven());

        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_CHAOS)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_CHAOS, $report['highest_passed']);

        // The next rung (one_real) is now the current rung the loop must clear.
        $this->assertSame(LongRunCertificationLadderService::RUNG_ONE_REAL, $report['current_rung']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_PENDING, $this->rung($report, LongRunCertificationLadderService::RUNG_ONE_REAL)['status']);
        $this->assertSame('real_cycle_proof_absent', $report['next_blocker']);
        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
    }

    public function test_chaos_rung_blocks_when_chaos_suite_fails(): void
    {
        // Sim passes but chaos suite explicitly failed => chaos rung is blocked with
        // a precise next_blocker; nothing above it can pass.
        $input = $this->simAndChaosProven();
        $input['chaos_report'] = ['passed' => false];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_BLOCKED, $this->rung($report, LongRunCertificationLadderService::RUNG_CHAOS)['status']);
        $this->assertSame('chaos_or_invariant_gate_failed', $report['next_blocker']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_CHAOS, $report['current_rung']);
    }

    public function test_a_simulated_cycle_is_never_counted_as_a_real_cycle(): void
    {
        // Even 1000 simulated cycles + chaos cannot satisfy any real rung. The real
        // rungs require proven real merges, never simulated counts.
        $report = $this->service()->evaluate($this->simAndChaosProven());

        foreach ([
            LongRunCertificationLadderService::RUNG_ONE_REAL,
            LongRunCertificationLadderService::RUNG_THREE_PACKETS,
            LongRunCertificationLadderService::RUNG_TEN_REAL,
        ] as $realRung) {
            $this->assertNotSame(
                LongRunCertificationLadderService::RUNG_PASSED,
                $this->rung($report, $realRung)['status'],
                "{$realRung} must not pass on simulated cycles alone",
            );
        }
        $this->assertTrue($report['claim_policy']['simulated_never_counted_as_real']);
    }

    public function test_real_rungs_pass_only_with_readiness_autonomy_isolation_and_proven_merges(): void
    {
        // Provide the full real foundation + 10 proven real merges => ten_real passes.
        $input = $this->simAndChaosProven();
        $input['ap805_readiness'] = ['status' => 'ready'];
        $input['ap806_autonomy'] = ['armed' => true];
        $input['isolation_status'] = ['isolated' => true];
        $input['real_cycles'] = [
            'real_merges' => 10,
            'distinct_packets_merged' => 5,
        ];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_ONE_REAL)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_THREE_PACKETS)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_TEN_REAL)['status']);
        $this->assertTrue($report['blocks'][LongRunCertificationLadderService::RUNG_TEN_REAL]);

        // Now drop autonomy => the first real rung is blocked, ten_real cannot pass.
        $input['ap806_autonomy'] = ['armed' => false];
        $blocked = $this->service()->evaluate($input);
        $this->assertSame(LongRunCertificationLadderService::RUNG_BLOCKED, $this->rung($blocked, LongRunCertificationLadderService::RUNG_ONE_REAL)['status']);
        $this->assertNotSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($blocked, LongRunCertificationLadderService::RUNG_TEN_REAL)['status']);
        $this->assertFalse($blocked['blocks'][LongRunCertificationLadderService::RUNG_TEN_REAL]);
    }

    public function test_skipping_a_rung_without_receipt_is_blocked(): void
    {
        // chaos failed; an operator requests skipping it but supplies NO valid
        // receipt (no actor/decision) => hard block, never a free pass.
        $input = $this->simAndChaosProven();
        $input['chaos_report'] = ['passed' => false];
        $input['skip_receipts'] = [
            'chaos' => true, // shorthand, not a real receipt
        ];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_BLOCKED, $this->rung($report, LongRunCertificationLadderService::RUNG_CHAOS)['status']);
        $this->assertSame('rung_skip_requested_without_operator_receipt', $report['next_blocker']);
    }

    public function test_skipping_a_rung_with_operator_receipt_is_allowed_and_promotion_continues(): void
    {
        // chaos failed, but the operator filed a valid decision receipt to skip it.
        // The rung is treated as passed and promotion continues to one_real.
        $input = $this->simAndChaosProven();
        $input['chaos_report'] = ['passed' => false];
        $input['skip_receipts'] = [
            ['rung' => 'chaos', 'decision' => 'skip', 'actor' => 'vitor', 'rationale' => 'chaos covered manually'],
        ];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_CHAOS)['status']);
        // Promotion continues: one_real is now the current rung (pending, not blocked by chaos).
        $this->assertSame(LongRunCertificationLadderService::RUNG_ONE_REAL, $report['current_rung']);
        $this->assertContains('rung_passed_by_operator_skip_receipt:chaos', $report['warnings']);
    }

    public function test_months_readiness_30d_is_blocked_without_7d(): void
    {
        // months-readiness => evaluate with --horizon. Provide everything through a
        // long soak that clears up to 3d but NOT 7d. The 30d horizon must be blocked.
        $input = $this->simAndChaosProven();
        $input['ap805_readiness'] = ['status' => 'ready'];
        $input['ap806_autonomy'] = ['armed' => true];
        $input['isolation_status'] = ['isolated' => true];
        $input['real_cycles'] = ['real_merges' => 10, 'distinct_packets_merged' => 5];
        $input['soak'] = ['days' => 3.0, 'ok' => true]; // 3d proven, 7d not
        $input['horizon'] = '30d';

        $report = $this->service()->evaluate($input);

        // Horizon truncates to the 30d rung (the full ladder here).
        $this->assertSame(LongRunCertificationLadderService::RUNG_THIRTY_DAYS, $report['horizon_top_rung']);
        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);

        // three_days passed, seven_days is the wall the loop is stuck at.
        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_THREE_DAYS)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_SEVEN_DAYS, $report['current_rung']);
        $this->assertSame('need_7d_continuous_productive_soak', $report['next_blocker']);

        // The 30d block summary is explicitly not reached without 7d.
        $this->assertFalse($report['blocks']['30d']);
        $this->assertFalse($report['blocks']['7d']);
    }

    public function test_horizon_truncates_the_ladder_to_the_requested_height(): void
    {
        // A 24h horizon must only surface rungs up to twenty_four_hours.
        $report = $this->service()->evaluate([
            'simulation_report' => ['simulated_cycles' => 1000, 'ok' => true],
            'horizon' => '24h',
        ]);

        $this->assertSame(LongRunCertificationLadderService::RUNG_TWENTY_FOUR_HOURS, $report['horizon_top_rung']);
        $rungNames = array_map(static fn (array $r): string => $r['rung'], $report['rungs']);
        $this->assertContains(LongRunCertificationLadderService::RUNG_TWENTY_FOUR_HOURS, $rungNames);
        $this->assertNotContains(LongRunCertificationLadderService::RUNG_THREE_DAYS, $rungNames);
        $this->assertNotContains(LongRunCertificationLadderService::RUNG_THIRTY_DAYS, $rungNames);
    }

    public function test_full_ladder_reaches_ok_only_when_every_rung_passes(): void
    {
        // Prove the entire ladder including a 30d soak with live backlog.
        $input = $this->simAndChaosProven();
        $input['ap805_readiness'] = ['status' => 'ready'];
        $input['ap806_autonomy'] = ['armed' => true];
        $input['isolation_status'] = ['isolated' => true];
        $input['real_cycles'] = ['real_merges' => 50, 'distinct_packets_merged' => 12];
        $input['soak'] = ['days' => 31.0, 'ok' => true];
        $input['backlog_depth'] = 25;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::STATUS_OK, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_THIRTY_DAYS, $report['highest_passed']);
        $this->assertNull($report['next_blocker']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertTrue($report['blocks']['30d']);
        $this->assertTrue($report['blocks']['7d']);
        $this->assertTrue($report['blocks']['24h']);
        $this->assertTrue($report['blocks'][LongRunCertificationLadderService::RUNG_TEN_REAL]);
    }

    public function test_exhausted_backlog_demotes_long_soak_rungs_to_not_real_productivity(): void
    {
        // NEGATIVE INVARIANT: a long soak on an exhausted backlog is filler, not
        // productivity. With backlog_depth=0 the soak rungs must not pass.
        $input = $this->simAndChaosProven();
        $input['ap805_readiness'] = ['status' => 'ready'];
        $input['ap806_autonomy'] = ['armed' => true];
        $input['isolation_status'] = ['isolated' => true];
        $input['real_cycles'] = ['real_merges' => 50, 'distinct_packets_merged' => 12];
        $input['soak'] = ['days' => 31.0, 'ok' => true];
        $input['backlog_depth'] = 0;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_TWO_HOURS, $report['current_rung']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_BLOCKED, $this->rung($report, LongRunCertificationLadderService::RUNG_TWO_HOURS)['status']);
        $this->assertSame('need_2h_continuous_productive_soak', $report['next_blocker']);
    }

    public function test_rung_above_a_blocked_rung_is_always_pending_never_passed(): void
    {
        // Even if a higher rung's own seam is fully present, it must stay pending
        // while a lower rung is unproven (no jumping the ladder).
        $input = [
            // sim absent => ten_simulated pending, so EVERYTHING above is pending
            'soak' => ['days' => 31.0, 'ok' => true],
            'real_cycles' => ['real_merges' => 99, 'distinct_packets_merged' => 99],
            'ap805_readiness' => ['status' => 'ready'],
            'ap806_autonomy' => ['armed' => true],
            'isolation_status' => ['isolated' => true],
        ];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LongRunCertificationLadderService::RUNG_TEN_SIMULATED, $report['current_rung']);
        // thirty_days must be pending despite its seam being present.
        $this->assertSame(LongRunCertificationLadderService::RUNG_PENDING, $this->rung($report, LongRunCertificationLadderService::RUNG_THIRTY_DAYS)['status']);
        $this->assertStringStartsWith('awaiting_lower_rung:', (string) $this->rung($report, LongRunCertificationLadderService::RUNG_THIRTY_DAYS)['next_blocker']);
        $this->assertNull($report['highest_passed']);
    }

    public function test_accepts_composed_bundle_via_fixture_input_seam(): void
    {
        // The wiring phase passes the whole composed bundle under `fixture`.
        $report = $this->service()->evaluate(['fixture' => $this->simAndChaosProven()]);

        $this->assertSame(LongRunCertificationLadderService::RUNG_PASSED, $this->rung($report, LongRunCertificationLadderService::RUNG_CHAOS)['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_ONE_REAL, $report['current_rung']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->simAndChaosProven();

        $first = $this->service()->evaluate($input);
        $second = $this->service()->evaluate($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A different ladder state must hash differently AND remain stable.
        $other = $input;
        $other['chaos_report'] = ['passed' => false];
        $o1 = $this->service()->evaluate($other);
        $o2 = $this->service()->evaluate($other);
        $this->assertSame($o1['report_hash'], $o2['report_hash']);
        $this->assertNotSame($first['report_hash'], $o1['report_hash']);
    }

    public function test_department_quality_bar_threshold_contract_is_a_dedicated_psr4_class(): void
    {
        $contractPath = app_path(
            'Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DepartmentQualityBarThresholdContract.php',
        );
        $ladderPath = app_path(
            'Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LongRunCertificationLadderService.php',
        );

        $this->assertFileExists($contractPath);
        $this->assertTrue(class_exists(DepartmentQualityBarThresholdContract::class));
        $this->assertStringNotContainsString(
            'class DepartmentQualityBarThresholdContract',
            (string) file_get_contents($ladderPath),
            'the department quality bar threshold contract must not live inside LongRunCertificationLadderService',
        );
        $this->assertSame(
            'atlas.software_company_stewardship.department_quality_bar_threshold.v1',
            DepartmentQualityBarThresholdContract::defaults()->toArray()['schema_version'],
        );
    }

    public function test_department_quality_bar_thresholds_empty_input_returns_default_contract(): void
    {
        $result = $this->service()->departmentQualityBarThresholds([]);

        $this->assertSame(DepartmentQualityBarThresholdContract::defaults()->toArray(), $result);
    }

    public function test_department_quality_bar_thresholds_dev_latency_breach_returns_concrete_output(): void
    {
        $result = $this->service()->departmentQualityBarThresholds([
            'department_metrics_snapshot' => [
                'dev' => [
                    'latency_p95' => 75.0,
                    'tests_pass_rate' => 0.97,
                    'scope_violation_rate' => 0.005,
                    'repair_loop_avg' => 0.8,
                ],
                'forge' => [
                    'obra_completion_rate' => 0.90,
                    'cert_pass_rate' => 0.95,
                    'rollback_rate' => 0.02,
                    'multi_agent_collision_rate' => 0.01,
                ],
            ],
        ]);

        $this->assertSame(1, $result['outputs']['breach_count']);
        $this->assertSame('dev', $result['outputs']['threshold_breaches'][0]['department_id']);
        $this->assertSame('latency_p95', $result['outputs']['threshold_breaches'][0]['metric']);
        $this->assertSame(75.0, $result['outputs']['threshold_breaches'][0]['observed']);
        $this->assertTrue($result['outputs']['would_block_ladder_promotion']);
        $this->assertFalse($result['outputs']['blocks_ladder_promotion']);
        $this->assertSame(
            DepartmentQualityBarThresholdContract::BLOCKER_DEPT_QUALITY_BAR_L3_BREACH,
            $result['outputs']['blocker_id'],
        );
    }

    public function test_evaluate_attaches_department_quality_bar_when_metrics_snapshot_present(): void
    {
        $input = $this->simAndChaosProven();
        $input['department_metrics_snapshot'] = [
            'dev' => [
                'latency_p95' => 75.0,
                'tests_pass_rate' => 0.97,
                'scope_violation_rate' => 0.005,
                'repair_loop_avg' => 0.8,
            ],
            'forge' => [
                'obra_completion_rate' => 0.90,
                'cert_pass_rate' => 0.95,
                'rollback_rate' => 0.02,
                'multi_agent_collision_rate' => 0.01,
            ],
        ];

        $report = $this->service()->evaluate($input);

        $this->assertArrayHasKey('department_quality_bar_thresholds', $report);
        $this->assertSame(1, $report['department_quality_bar_thresholds']['outputs']['breach_count']);
        $this->assertSame('latency_p95', $report['department_quality_bar_thresholds']['outputs']['threshold_breaches'][0]['metric']);
        $this->assertContains(
            DepartmentQualityBarThresholdContract::BLOCKER_DEPT_QUALITY_BAR_L3_BREACH,
            $report['warnings'],
        );
    }

    public function test_default_empty_input_does_not_crash_and_blocks_honestly(): void
    {
        // Diagnostic default: nothing proven => first rung blocked, status=blocked,
        // never a crash and never `ok`.
        $report = $this->service()->evaluate();

        $this->assertSame(LongRunCertificationLadderService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('AP-810', $report['ap_contract']);
        $this->assertSame('LHL-19', $report['slice_id']);
        $this->assertSame(LongRunCertificationLadderService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(LongRunCertificationLadderService::RUNG_TEN_SIMULATED, $report['current_rung']);
        $this->assertNull($report['highest_passed']);
        $this->assertCount(13, $report['rungs']);
        $this->assertNotSame('success', $report['status']);
    }
}
