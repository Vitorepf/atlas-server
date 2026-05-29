<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopQualityDriftDetectorService;
use Tests\TestCase;

final class LoopQualityDriftDetectorServiceTest extends TestCase
{
    private function service(): LoopQualityDriftDetectorService
    {
        return app(LoopQualityDriftDetectorService::class);
    }

    /**
     * A healthy window of cycles: no repair, no blocks, flat tests, varied
     * subsystems, no reverts, complete evidence, no operator hand-holding.
     *
     * @return array<string,mixed>
     */
    private function stableHistory(): array
    {
        $cycles = [];
        $subsystems = ['loop_control', 'evidence_pack', 'router', 'sandbox', 'inbox', 'portfolio'];
        for ($i = 0; $i < 6; $i++) {
            $cycles[] = [
                'cycle_index' => $i + 1,
                'status' => 'merged',
                'repair_required' => false,
                'blocked' => false,
                'reverted' => false,
                'test_duration_seconds' => 100,
                'subsystem' => $subsystems[$i],
                'complexity' => 10.0,
                'duplication' => 2.0,
                'evidence_complete' => true,
                'operator_intervened' => false,
            ];
        }

        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-stable',
            'history' => $cycles,
        ];
    }

    public function test_stable_history_is_stable_with_no_stop_promotion(): void
    {
        $report = $this->service()->detect($this->stableHistory());

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_STABLE, $report['status']);
        $this->assertFalse($report['stop_promotion']);
        $this->assertSame([], $report['recommended_actions']);
        $this->assertSame(0, $report['drift_score']);
        $this->assertSame([], $report['alarm_signals']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame(LoopQualityDriftDetectorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-12', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
    }

    public function test_rising_repair_and_blocked_rates_drive_degraded_with_reduce_tier_and_stop_promotion(): void
    {
        // High repair rate + high blocked rate + reverts => multiple alarms => degraded.
        $input = [
            'run_id' => 'run-bad',
            'repair_required_rate' => 0.6,
            'blocked_rate' => 0.55,
            'revert_rollback_count' => 4,
            // enough window so this is not "insufficient history"
            'history' => array_fill(0, 6, ['status' => 'block']),
        ];

        $report = $this->service()->detect($input);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_DEGRADED, $report['status']);
        $this->assertTrue($report['stop_promotion'], 'a degraded loop must never be promoted to a longer run');
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_REDUCE_AUTONOMY_TIER, $report['recommended_actions']);
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_STOP_LONG_RUN_PROMOTION, $report['recommended_actions']);
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_GENERATE_REPAIR_BACKLOG, $report['recommended_actions']);
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_PAUSE_HIGH_RISK_PACKETS, $report['recommended_actions']);
        $this->assertContains('repair_required_rate', $report['alarm_signals']);
        $this->assertContains('blocked_rate', $report['alarm_signals']);
        $this->assertSame('stop_long_run_promotion', $report['next_action']);
    }

    public function test_warn_level_signals_drive_drifting_without_hard_stop_promotion(): void
    {
        // Warn-level signals only (no alarm), but enough aggregate drift to tip
        // the loop into `drifting` rather than `degraded`.
        $input = [
            'run_id' => 'run-drift',
            'repair_required_rate' => 0.25, // warn (>=0.20, <0.40)
            'blocked_rate' => 0.30,         // warn (>=0.25, <0.50)
            'history' => array_fill(0, 6, ['status' => 'merged']),
        ];

        $report = $this->service()->detect($input);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_DRIFTING, $report['status']);
        $this->assertSame([], $report['alarm_signals']);
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_REDUCE_AUTONOMY_TIER, $report['recommended_actions']);
        $this->assertContains('repair_required_rate', $report['warn_signals']);
        // Warn-only drift still recommends stopping promotion of a longer run, but
        // does not force the hard stop_promotion flag (reserved for alarms/degraded).
        $this->assertFalse($report['stop_promotion']);
    }

    public function test_single_warn_signal_alone_stays_stable(): void
    {
        // One warn-level signal (drift_score=1, below the drifting threshold) is
        // noise, not drift — the loop stays stable.
        $report = $this->service()->detect([
            'run_id' => 'run-noise',
            'repair_required_rate' => 0.25, // warn only
            'history' => array_fill(0, 6, ['status' => 'merged']),
        ]);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_STABLE, $report['status']);
        $this->assertFalse($report['stop_promotion']);
        $this->assertContains('repair_required_rate', $report['warn_signals']);
    }

    public function test_single_alarm_signal_forces_stop_promotion_even_while_drifting(): void
    {
        // One alarm-level signal => drifting + hard stop_promotion=true.
        $input = [
            'run_id' => 'run-alarm',
            'blocked_rate' => 0.6, // alarm
            'history' => array_fill(0, 6, ['status' => 'block']),
        ];

        $report = $this->service()->detect($input);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_DRIFTING, $report['status']);
        $this->assertContains('blocked_rate', $report['alarm_signals']);
        $this->assertTrue($report['stop_promotion'], 'an alarm-level signal must stop long-run promotion');
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_STOP_LONG_RUN_PROMOTION, $report['recommended_actions']);
        $this->assertContains(LoopQualityDriftDetectorService::ACTION_PAUSE_HIGH_RISK_PACKETS, $report['recommended_actions']);
    }

    public function test_repair_and_blocked_rates_derived_from_history(): void
    {
        // No direct rate overrides — derive from per-cycle flags/status.
        $cycles = [];
        for ($i = 0; $i < 6; $i++) {
            $cycles[] = [
                'cycle_index' => $i + 1,
                // 4 of 6 blocked, 3 of 6 needed repair
                'status' => $i < 4 ? 'blocked' : 'merged',
                'repair_required' => $i < 3,
                'subsystem' => 'router',
            ];
        }

        $report = $this->service()->detect(['history' => $cycles]);

        $this->assertGreaterThanOrEqual(0.6, $report['signals']['blocked_rate']['value']);
        $this->assertGreaterThanOrEqual(0.4, $report['signals']['repair_required_rate']['value']);
        $this->assertContains($report['status'], [
            LoopQualityDriftDetectorService::STATUS_DRIFTING,
            LoopQualityDriftDetectorService::STATUS_DEGRADED,
        ]);
        $this->assertTrue($report['stop_promotion']);
    }

    public function test_repeated_subsystem_churn_requests_architecture_or_security_review(): void
    {
        $cycles = [];
        for ($i = 0; $i < 6; $i++) {
            $cycles[] = [
                'cycle_index' => $i + 1,
                'status' => 'merged',
                'subsystem' => 'loop_control', // same subsystem churned 6 times => alarm
            ];
        }

        $report = $this->service()->detect(['history' => $cycles]);

        $this->assertSame(6, $report['signals']['repeated_subsystem_churn']['value']);
        $this->assertContains('repeated_subsystem_churn', $report['alarm_signals']);
        $this->assertContains(
            LoopQualityDriftDetectorService::ACTION_REQUEST_ARCH_OR_SECURITY_REVIEW,
            $report['recommended_actions'],
        );
    }

    public function test_rising_test_duration_trend_is_detected(): void
    {
        $cycles = [];
        $durations = [100, 130, 170, 220, 290, 380]; // steeply rising
        foreach ($durations as $i => $d) {
            $cycles[] = ['cycle_index' => $i + 1, 'status' => 'merged', 'test_duration_seconds' => $d, 'subsystem' => 'mod'.$i];
        }

        $report = $this->service()->detect(['history' => $cycles]);

        $this->assertGreaterThan(0.0, $report['signals']['test_duration_trend']['value']);
        $this->assertGreaterThanOrEqual(1, $report['signals']['test_duration_trend']['score']);
    }

    public function test_low_evidence_completeness_is_an_alarm_signal(): void
    {
        $report = $this->service()->detect([
            'evidence_completeness' => 0.4, // <= 0.60 alarm
            'history' => array_fill(0, 6, ['status' => 'merged']),
        ]);

        $this->assertContains('evidence_completeness', $report['alarm_signals']);
        $this->assertContains($report['status'], [
            LoopQualityDriftDetectorService::STATUS_DRIFTING,
            LoopQualityDriftDetectorService::STATUS_DEGRADED,
        ]);
    }

    public function test_complexity_duplication_trend_is_optional_when_unavailable(): void
    {
        // No complexity/duplication fields => signal is unavailable, NOT counted
        // as drift (the spec says "when available").
        $report = $this->service()->detect($this->stableHistoryWithoutComplexity());

        $this->assertNull($report['signals']['complexity_duplication_trend']['value']);
        $this->assertSame(0, $report['signals']['complexity_duplication_trend']['score']);
        $this->assertContains('signal_unavailable:complexity_duplication_trend', $report['warnings']);
        // Absence of an optional signal does not by itself degrade a stable loop.
        $this->assertSame(LoopQualityDriftDetectorService::STATUS_STABLE, $report['status']);
    }

    public function test_high_operator_intervention_frequency_drives_drift(): void
    {
        $report = $this->service()->detect([
            'operator_intervention_frequency' => 0.5, // >= 0.45 alarm
            'history' => array_fill(0, 6, ['status' => 'merged']),
        ]);

        $this->assertContains('operator_intervention_frequency', $report['alarm_signals']);
        $this->assertTrue($report['stop_promotion']);
    }

    public function test_thin_history_is_never_falsely_certified_stable(): void
    {
        // A 1-cycle window with no drift evidence must NOT be reported as stable.
        $report = $this->service()->detect([
            'history' => [['cycle_index' => 1, 'status' => 'merged', 'subsystem' => 'mod']],
        ]);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_DRIFTING, $report['status']);
        $this->assertContains('insufficient_history_for_stable', $report['warnings']);
    }

    public function test_accepts_drift_record_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole record under `fixture`; direct keys win.
        $report = $this->service()->detect(['fixture' => $this->stableHistory()]);

        $this->assertSame(LoopQualityDriftDetectorService::STATUS_STABLE, $report['status']);
        $this->assertSame('run-stable', $report['run_id']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->stableHistory();

        $first = $this->service()->detect($input);
        $second = $this->service()->detect($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A degraded input hashes stably too AND differs from the stable hash.
        $bad = [
            'run_id' => 'run-stable',
            'repair_required_rate' => 0.6,
            'blocked_rate' => 0.6,
            'history' => array_fill(0, 6, ['status' => 'block']),
        ];
        $b1 = $this->service()->detect($bad);
        $b2 = $this->service()->detect($bad);
        $this->assertSame($b1['report_hash'], $b2['report_hash']);
        $this->assertNotSame($first['report_hash'], $b1['report_hash']);
    }

    public function test_report_hash_is_order_independent_for_history(): void
    {
        // History ordering by cycle_index makes the slope deterministic regardless
        // of the order rows arrive in.
        $base = $this->stableHistory();
        $shuffled = $base;
        $shuffled['history'] = array_reverse($base['history']);

        $a = $this->service()->detect($base);
        $b = $this->service()->detect($shuffled);

        $this->assertSame($a['report_hash'], $b['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_is_stable(): void
    {
        // Diagnostic default: empty history shows no evidence of drift => stable,
        // never a crash and never a false degraded.
        $report = $this->service()->detect();

        $this->assertSame(LoopQualityDriftDetectorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(LoopQualityDriftDetectorService::STATUS_STABLE, $report['status']);
        $this->assertFalse($report['stop_promotion']);
        $this->assertSame([], $report['recommended_actions']);
        $this->assertSame('LHL-12', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
    }

    /**
     * Stable history but with the complexity/duplication fields removed, to prove
     * the optional signal stays unavailable without dragging status off stable.
     *
     * @return array<string,mixed>
     */
    private function stableHistoryWithoutComplexity(): array
    {
        $input = $this->stableHistory();
        foreach ($input['history'] as &$cycle) {
            unset($cycle['complexity'], $cycle['duplication']);
        }
        unset($cycle);

        return $input;
    }
}
