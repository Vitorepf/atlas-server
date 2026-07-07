<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasSpecCritiqueCalibrationLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * T4-S6 (Obra #17) — the counterfactual close-replay that calibrates the P3 critic. The
 * ledger records the critic's bet (verdict + concerns) at obra open and resolves it at
 * close (clean outcome?), then the curve surfaces refutation-reversals (flagged but
 * clean) and a Brier score — UNMEASURED until enough resolved pairs (no fabrication).
 */
final class AtlasSpecCritiqueCalibrationLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-critique-calib-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_curve_is_unmeasured_until_min_obras_resolved(): void
    {
        $ledger = new AtlasSpecCritiqueCalibrationLedger($this->root);

        // One resolved pair — below the default floor of 5.
        $ledger->recordBet('obra-1', ['verdict' => 'concerns_found', 'concerns' => ['x'], 'concerns_count' => 1]);
        $ledger->resolve('obra-1', false);

        $curve = $ledger->curve(5);
        self::assertFalse($curve['measured'], 'a single pair is not enough to score — honest unmeasured, never fabricated');
        self::assertSame(1, $curve['resolved_pairs']);
    }

    public function test_curve_scores_calibration_and_counts_refutation_reversals(): void
    {
        $ledger = new AtlasSpecCritiqueCalibrationLedger($this->root);

        // 3 flagged-and-problem (true positives) …
        foreach (['tp1', 'tp2', 'tp3'] as $id) {
            $ledger->recordBet($id, ['verdict' => 'concerns_found', 'concerns' => ['c'], 'concerns_count' => 1]);
            $ledger->resolve($id, false); // not clean = the concern materialized
        }
        // … 2 flagged-but-clean (refutation-reversals: cried wolf) …
        foreach (['rr1', 'rr2'] as $id) {
            $ledger->recordBet($id, ['verdict' => 'concerns_found', 'concerns' => ['c'], 'concerns_count' => 1]);
            $ledger->resolve($id, true); // clean outcome
        }
        // … 1 governed-and-clean (true negative).
        $ledger->recordBet('tn1', ['verdict' => 'governed', 'concerns' => [], 'concerns_count' => 0]);
        $ledger->resolve('tn1', true);
        // A non-prediction bet MUST be excluded from the curve.
        $ledger->recordBet('noise', ['verdict' => 'no_brain_signal', 'concerns' => [], 'concerns_count' => 0]);
        $ledger->resolve('noise', true);

        $curve = $ledger->curve(5);

        self::assertTrue($curve['measured']);
        self::assertSame(6, $curve['resolved_pairs'], 'no_brain_signal is excluded (6 real-call pairs, not 7)');
        self::assertSame(2, $curve['refutation_reversals'], 'flagged-but-clean = refutation-reversal');
        self::assertSame(3, $curve['confusion']['tp']);
        self::assertSame(2, $curve['confusion']['fp']);
        self::assertSame(1, $curve['confusion']['tn']);
        self::assertSame(0, $curve['confusion']['fn']);
        // flagged precision = tp/(tp+fp) = 3/5 = 0.6
        self::assertSame(0.6, $curve['flagged_precision']);
        // Brier: 5 flagged (pred=1) — 3 problem (err 0) + 2 clean (err 1); 1 governed clean (pred=0, err 0).
        // sum = 2, /6 ≈ 0.333
        self::assertSame(0.333, $curve['brier']);
    }

    public function test_last_bet_and_outcome_win_per_obra(): void
    {
        $ledger = new AtlasSpecCritiqueCalibrationLedger($this->root);

        // Same obra re-run: the LAST bet/outcome is authoritative (idempotent replay).
        $ledger->recordBet('obra-x', ['verdict' => 'concerns_found', 'concerns' => ['c'], 'concerns_count' => 1]);
        $ledger->resolve('obra-x', false);
        $ledger->recordBet('obra-x', ['verdict' => 'governed', 'concerns' => [], 'concerns_count' => 0]);
        $ledger->resolve('obra-x', true);

        // Pad to the floor with distinct clean/governed pairs.
        foreach (['p1', 'p2', 'p3', 'p4'] as $id) {
            $ledger->recordBet($id, ['verdict' => 'governed', 'concerns' => [], 'concerns_count' => 0]);
            $ledger->resolve($id, true);
        }

        $curve = $ledger->curve(5);
        self::assertTrue($curve['measured']);
        self::assertSame(5, $curve['resolved_pairs'], 'obra-x collapses to one pair (last wins)');
        self::assertSame(0, $curve['refutation_reversals'], 'the last state of obra-x is governed+clean, not a reversal');
    }
}
