<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternChampionGate;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — proves the ChampionGate is the fail-closed promotion fechadura.
 *
 * The load-bearing assertions: a self-approved challenger is blocked EVEN WHEN it wins on a fresh eval;
 * a stale eval blocks; a margin-miss blocks; a guardrail regression blocks; only an all-green challenge
 * promotes; and any missing field collapses to "keep the champion". The gate is also proven pure — its
 * verdict is identical with or without an injected ledger — and proven to record on demand.
 */
final class AtlasLoopPatternChampionGateTest extends \Tests\TestCase
{
    /**
     * A fully-promotable challenge: distinct proposer/approver, fresh+named eval battery, a strict win
     * past the margin, no guardrail regression. Individual tests mutate ONE field to prove that field's
     * invariant in isolation.
     *
     * @return array<string,mixed>
     */
    private function winningChallenge(array $overrides = []): array
    {
        return array_merge([
            'champion_id' => 'docs_sweep@1.0.0',
            'challenger_id' => 'docs_sweep@1.1.0',
            'proposer' => 'lane:implementer',
            'approver' => 'lane:verifier',
            'eval_fresh' => true,
            'eval_battery_id' => 'battery-2026-06-19-docs',
            'champion_score' => 0.80,
            'challenger_score' => 0.90,
            'margin' => 0.05,
            'guardrail_regressed' => false,
        ], $overrides);
    }

    public function test_all_checks_pass_promotes(): void
    {
        $result = (new AtlasLoopPatternChampionGate())->evaluate($this->winningChallenge());

        $this->assertTrue($result['promote']);
        $this->assertSame(
            [
                'self_approval_blocked' => true,
                'fresh_eval_required' => true,
                'beats_champion' => true,
                'no_guardrail_regression' => true,
            ],
            $result['checks']
        );
        $this->assertStringContainsString('promote', $result['reason']);
    }

    public function test_self_approval_blocks_even_when_scores_better_and_eval_fresh(): void
    {
        // proposer === approver: the creator approving their own challenger. Everything ELSE is a pass
        // (fresh battery, clear win, no regression) — proving the identity lane alone vetoes promotion.
        $challenge = $this->winningChallenge([
            'proposer' => 'lane:champion-author',
            'approver' => 'lane:champion-author',
        ]);

        $result = (new AtlasLoopPatternChampionGate())->evaluate($challenge);

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['self_approval_blocked']);
        // The other three invariants still hold — isolating the cause to self-approval.
        $this->assertTrue($result['checks']['fresh_eval_required']);
        $this->assertTrue($result['checks']['beats_champion']);
        $this->assertTrue($result['checks']['no_guardrail_regression']);
        $this->assertStringContainsString('self_approval_blocked', $result['reason']);
    }

    public function test_stale_eval_blocks(): void
    {
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['eval_fresh' => false])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['fresh_eval_required']);
        $this->assertTrue($result['checks']['beats_champion']);
    }

    public function test_missing_eval_battery_id_blocks_even_if_fresh(): void
    {
        // eval_fresh=true but no battery id ⇒ unauditable ⇒ treated as not-fresh.
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['eval_battery_id' => ''])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['fresh_eval_required']);
    }

    public function test_challenger_not_beating_margin_blocks(): void
    {
        // champion 0.80, challenger 0.84, margin 0.05 ⇒ 0.84 is NOT > 0.85 ⇒ block.
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['challenger_score' => 0.84, 'margin' => 0.05])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['beats_champion']);
    }

    public function test_exact_margin_tie_blocks_strict_inequality(): void
    {
        // challenger == champion + margin exactly ⇒ NOT strictly better ⇒ block (proves the ">" is strict).
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['champion_score' => 0.80, 'challenger_score' => 0.85, 'margin' => 0.05])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['beats_champion']);
    }

    public function test_zero_margin_strict_win_promotes(): void
    {
        // Default margin 0.0 still requires a strict win; 0.81 > 0.80 ⇒ promote.
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['champion_score' => 0.80, 'challenger_score' => 0.81, 'margin' => 0.0])
        );

        $this->assertTrue($result['promote']);
        $this->assertTrue($result['checks']['beats_champion']);
    }

    public function test_guardrail_regression_blocks(): void
    {
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['guardrail_regressed' => true])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['no_guardrail_regression']);
        $this->assertTrue($result['checks']['beats_champion']);
    }

    public function test_missing_guardrail_flag_fails_closed(): void
    {
        // Absence of a guardrail flag is treated as a regression (no proof of safety == unsafe).
        $challenge = $this->winningChallenge();
        unset($challenge['guardrail_regressed']);

        $result = (new AtlasLoopPatternChampionGate())->evaluate($challenge);

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['no_guardrail_regression']);
    }

    public function test_missing_scores_fail_closed(): void
    {
        $challenge = $this->winningChallenge();
        unset($challenge['challenger_score'], $challenge['champion_score']);

        $result = (new AtlasLoopPatternChampionGate())->evaluate($challenge);

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['beats_champion']);
    }

    public function test_non_finite_score_fails_closed(): void
    {
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['challenger_score' => INF])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['beats_champion']);
    }

    public function test_empty_proposer_and_approver_fail_closed(): void
    {
        // Anonymous "approval" cannot prove independence ⇒ blocked even though they are not equal-strings
        // in a meaningful sense (both empty would be equal anyway, but empties are vetoed explicitly).
        $result = (new AtlasLoopPatternChampionGate())->evaluate(
            $this->winningChallenge(['proposer' => '', 'approver' => ''])
        );

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['checks']['self_approval_blocked']);
    }

    public function test_fully_empty_challenge_fails_closed_with_no_throw(): void
    {
        $result = (new AtlasLoopPatternChampionGate())->evaluate([]);

        $this->assertFalse($result['promote']);
        $this->assertSame(
            [
                'self_approval_blocked' => false,
                'fresh_eval_required' => false,
                'beats_champion' => false,
                'no_guardrail_regression' => false,
            ],
            $result['checks']
        );
    }

    public function test_verdict_is_pure_with_or_without_ledger(): void
    {
        $challenge = $this->winningChallenge();
        $path = sys_get_temp_dir().'/atlas-champion-gate-'.uniqid().'.jsonl';
        $ledger = new AtlasLoopPatternLearningLedger($path);

        $withoutLedger = (new AtlasLoopPatternChampionGate())->evaluate($challenge);
        $withLedger = (new AtlasLoopPatternChampionGate($ledger))->evaluate($challenge);

        // The injected ledger never changes the verdict — purity invariant.
        $this->assertSame($withoutLedger['promote'], $withLedger['promote']);
        $this->assertSame($withoutLedger['checks'], $withLedger['checks']);

        @unlink($path);
    }

    public function test_records_decision_to_injected_ledger(): void
    {
        $path = sys_get_temp_dir().'/atlas-champion-gate-'.uniqid().'.jsonl';
        $ledger = new AtlasLoopPatternLearningLedger($path);

        (new AtlasLoopPatternChampionGate($ledger))->evaluate($this->winningChallenge());

        $rows = $ledger->all();
        $this->assertCount(1, $rows);
        $this->assertSame('docs_sweep@1.1.0', $rows[0]['pattern_id']);
        $this->assertSame(AtlasLoopPatternLearningLedger::RESULT_SUCCESS, $rows[0]['result']);
        $this->assertSame('champion_gate_decision', $rows[0]['objective_class']);

        @unlink($path);
    }

    public function test_records_block_decision_to_injected_ledger(): void
    {
        $path = sys_get_temp_dir().'/atlas-champion-gate-'.uniqid().'.jsonl';
        $ledger = new AtlasLoopPatternLearningLedger($path);

        (new AtlasLoopPatternChampionGate($ledger))->evaluate(
            $this->winningChallenge(['guardrail_regressed' => true])
        );

        $rows = $ledger->all();
        $this->assertCount(1, $rows);
        $this->assertSame(AtlasLoopPatternLearningLedger::RESULT_BLOCKED, $rows[0]['result']);
        $this->assertContains('no_guardrail_regression', $rows[0]['gates_failed']);

        @unlink($path);
    }
}
