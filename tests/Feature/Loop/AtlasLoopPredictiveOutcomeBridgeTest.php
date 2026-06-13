<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\PredictiveFailure\AtlasLoopPredictiveOutcomeBridge;
use App\Services\Ai\Cognitive\PredictiveFailure\PredictiveCodeIntelligenceCorrelationGateService;
use App\Services\Ai\Cognitive\PredictiveFailure\PredictiveFailureCalibrationMetricsService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L6-11 FROZEN CONTRACT — the predictive surface must compute calibration on REAL loop
 * data, not stay null forever.
 *
 * Before this wiring the only producer of a predictive-failure insertion was the manual
 * `atlas:predict failure` CLI, so in production total_insertions=0 / brier_score=null.
 * The {@see AtlasLoopPredictiveOutcomeBridge} records a real prediction + observed outcome
 * on a real loop GRIND event into the same table the metrics service reads. These tests
 * freeze:
 *   - default-OFF governance (flag-gated; passive loop telemetry never auto-runs),
 *   - the core proof: recorded predictions + reconciled outcomes => NON-NULL brier_score
 *     and avg_calibration_error from the live metrics service on real loop rows,
 *   - fail-open (storage unavailable => skipped, never crashes a grind),
 *   - that the L6-11 correlation gate certifies once enough real outcomes accrue.
 */
final class AtlasLoopPredictiveOutcomeBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_09_170000_create_predictive_failure_insertions_table.php'))->up();

        Config::set('atlas.loop.predictive_outcome_bridge.enabled', true);
        Config::set('atlas.loop.predictive_outcome_bridge.domain', 'programming');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('predictive_failure_calibration_metrics');
        Schema::dropIfExists('predictive_failure_insertions');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_disabled_by_default_so_loop_telemetry_never_auto_runs(): void
    {
        Config::set('atlas.loop.predictive_outcome_bridge.enabled', false);

        $result = app(AtlasLoopPredictiveOutcomeBridge::class)->recordGrind(
            ['status' => 'winner', 'has_winner' => true, 'proposals' => 1, 'scenarios_explored' => 4],
            ['objective' => 'tighten guard', 'target_path' => 'app/Foo.php'],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('loop_predictive_bridge_disabled', $result['reason']);
        $this->assertSame(0, DB::table('predictive_failure_insertions')->count());

        // Governance contract is the frozen authority for the surface.
        $gov = AtlasLoopPredictiveOutcomeBridge::governanceContract();
        $this->assertTrue($gov['flag_gated']);
        $this->assertTrue($gov['default_off']);
        $this->assertTrue($gov['fail_open']);
        $this->assertFalse($gov['writes_code']);
        $this->assertFalse($gov['mutates_proposals']);
        $this->assertFalse($gov['merges_to_main']);
        $this->assertTrue($gov['distinct_from_learning_surface']);
        $this->assertTrue($gov['records_real_prediction_and_observed_outcome']);
    }

    public function test_recorded_predictions_and_reconciled_outcomes_yield_non_null_calibration_metric(): void
    {
        $bridge = app(AtlasLoopPredictiveOutcomeBridge::class);

        // Three REAL loop grind events: a winner (success), a no_winner (partial), and a
        // failed grind (failure). Each records a prediction AND reconciles its outcome.
        $winner = $bridge->recordGrind(
            ['status' => 'winner', 'has_winner' => true, 'proposals' => 1, 'scenarios_explored' => 6],
            ['objective' => 'add numeric guard', 'target_path' => 'app/Services/Foo.php'],
        );
        $noWinner = $bridge->recordGrind(
            ['status' => 'no_winner', 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 8],
            ['objective' => 'refactor parser', 'target_path' => 'app/Services/Bar.php'],
        );
        $failed = $bridge->recordGrind(
            ['status' => 'failed', 'has_winner' => false, 'reason' => 'frozen judge timeout', 'scenarios_explored' => 2],
            ['objective' => 'wire adapter', 'target_path' => 'app/Services/Baz.php'],
        );

        foreach ([$winner, $noWinner, $failed] as $recorded) {
            $this->assertSame('recorded', $recorded['status'], 'each terminal grind must record telemetry');
            $this->assertSame('loop_grind_outcome', $recorded['source_type']);
            $this->assertNotNull($recorded['prediction_calibration_error']);
        }
        $this->assertSame('success', $winner['outcome']);
        $this->assertSame('partial', $noWinner['outcome']);
        $this->assertSame('failure', $failed['outcome']);

        // All three persisted as RESOLVED rows (outcome + calibration error) in the SAME
        // table the metrics service reads — no backfill, no fixture.
        $this->assertSame(3, DB::table('predictive_failure_insertions')->count());
        $this->assertSame(3, DB::table('predictive_failure_insertions')->whereNotNull('prediction_calibration_error')->count());
        $this->assertSame(2, DB::table('predictive_failure_insertions')
            ->whereNotNull('predicted_failure_signature_key')->count(), 'failure + partial carry a signature');

        // THE PROOF: the live metrics service computes a NON-NULL brier_score and a
        // non-null avg_calibration_error on real loop data (was null forever).
        $metrics = app(PredictiveFailureCalibrationMetricsService::class)->compute('programming', 60);
        $this->assertSame('computed', $metrics['status']);
        $this->assertSame(3, $metrics['total_insertions']);
        $this->assertSame(3, $metrics['outcomes_recorded']);
        $this->assertNotNull($metrics['avg_calibration_error']);
        $this->assertNotNull($metrics['brier_score']);
        $this->assertIsFloat($metrics['brier_score']);
        $this->assertGreaterThanOrEqual(0.0, $metrics['brier_score']);
        $this->assertLessThanOrEqual(1.0, $metrics['brier_score']);

        // Outcome ledger events were emitted (audit trail for each reconciliation).
        $this->assertSame(3, AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PredictiveFailureInserted->value)->count());
        $this->assertGreaterThanOrEqual(2, AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PredictiveFailureOutcomeFailure->value)->count());
    }

    public function test_backpressure_grind_is_not_reconcilable_so_records_nothing(): void
    {
        $result = app(AtlasLoopPredictiveOutcomeBridge::class)->recordGrind(
            ['status' => 'backpressure', 'has_winner' => false, 'reason' => 'disk_low'],
            ['objective' => 'x', 'target_path' => 'app/Y.php'],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('loop_grind_outcome_not_reconcilable', $result['reason']);
        $this->assertSame(0, DB::table('predictive_failure_insertions')->count());
    }

    public function test_fail_open_when_storage_unavailable(): void
    {
        Schema::dropIfExists('predictive_failure_calibration_metrics');
        Schema::dropIfExists('predictive_failure_insertions');

        $result = app(AtlasLoopPredictiveOutcomeBridge::class)->recordGrind(
            ['status' => 'winner', 'has_winner' => true, 'proposals' => 1, 'scenarios_explored' => 1],
            ['objective' => 'x', 'target_path' => 'app/Y.php'],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('predictive_failure_storage_unavailable', $result['reason']);
    }

    public function test_l6_11_correlation_gate_certifies_once_real_loop_outcomes_accrue(): void
    {
        $bridge = app(AtlasLoopPredictiveOutcomeBridge::class);

        // Seed 5 real, well-calibrated loop outcomes whose calibration error stays under
        // the gate floors (predicted ~0.2 success-leaning vs observed). We pass explicit
        // low-risk signals so the estimator predicts a low failure probability for the
        // successes (small error) and the failures carry a real signature.
        $lowRisk = ['dreyfus_stage' => 5, 'kg_gap_score' => 0.0, 'decay_score' => 0.0, 'failure_history_signal' => 0.0];
        foreach (['app/A.php', 'app/B.php', 'app/C.php'] as $path) {
            $bridge->recordGrind(
                ['status' => 'winner', 'has_winner' => true, 'proposals' => 1, 'scenarios_explored' => 3],
                ['objective' => 'safe change', 'target_path' => $path, 'signals' => $lowRisk],
            );
        }
        $highRisk = ['dreyfus_stage' => 1, 'kg_gap_score' => 1.0, 'decay_score' => 1.0, 'failure_history_signal' => 1.0];
        foreach (['app/D.php', 'app/E.php'] as $path) {
            $bridge->recordGrind(
                ['status' => 'failed', 'has_winner' => false, 'reason' => 'judge rejected', 'scenarios_explored' => 4],
                ['objective' => 'risky change', 'target_path' => $path, 'signals' => $highRisk],
            );
        }

        // Drive the L6-11 correlation gate in 'live' mode against a READY code gate
        // (injected so the assertion targets the predictive-correlation half, not the
        // live code index) and the REAL metrics/history just produced.
        $gate = app(PredictiveCodeIntelligenceCorrelationGateService::class)->evaluate([
            'fixture' => 'live',
            'domain' => 'programming',
            'window_days' => 60,
            'min_outcomes' => 3,
            'min_failure_signature_outcomes' => 1,
            'max_avg_calibration_error' => 0.35,
            'max_brier_score' => 0.25,
            'code_gate_report' => [
                'schema_version' => 'atlas.code_intelligence.gate.v1',
                'status' => 'ready',
                'summary' => ['symbol_count' => 100, 'module_count' => 5],
                'metrics' => ['drift_total' => 0],
                'blockers' => [],
            ],
        ]);

        $this->assertTrue($gate['certified'], 'real loop outcomes must satisfy the L6-11 correlation gate: '.json_encode($gate['blockers']));
        $this->assertSame('predictive_code_intelligence_correlated', $gate['status']);
        $this->assertGreaterThanOrEqual(5, $gate['assessment']['outcomes_recorded']);
        $this->assertGreaterThanOrEqual(2, $gate['assessment']['failure_signature_outcomes']);
        $this->assertNotNull($gate['assessment']['brier_score']);
        $this->assertNotNull($gate['assessment']['avg_calibration_error']);
        // The gate certifies on REAL data without minting predictions or backfilling.
        $this->assertTrue($gate['claim_policy']['does_not_mint_predictions']);
        $this->assertTrue($gate['claim_policy']['does_not_backfill_outcomes']);
    }
}
