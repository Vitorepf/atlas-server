<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAntiGoodhartAuditor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageScorer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPortfolioBalancer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSelfImprovementCycle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSelfImprovementCycleTest extends TestCase
{
    private function cycle(): AtlasExternalBrainSelfImprovementCycle
    {
        return new AtlasExternalBrainSelfImprovementCycle(
            new AtlasExternalBrainLeverageScorer,
            new AtlasExternalBrainPortfolioBalancer,
            new AtlasExternalBrainHighValueBatchComposer,
            new AtlasExternalBrainAntiGoodhartAuditor,
        );
    }

    private function proposal(string $id, string $category, float $score = 0.80): array
    {
        return ['task_packet_id' => $id, 'category' => $category, 'final_score' => $score];
    }

    private function outcome(string $outcome, string $category): array
    {
        return ['outcome' => $outcome, 'category' => $category];
    }

    // ── AC1: accepted / repair_required / held separated by evidence and risk ──

    public function test_proposals_with_clean_outcomes_go_to_accept(): void
    {
        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('p1', 'gate-impl', 0.90)],
            [], // no bad history
        );

        $this->assertCount(1, $r['next_wave_decisions']['accept']);
        $this->assertSame([], $r['next_wave_decisions']['repair_required']);
        $this->assertSame([], $r['next_wave_decisions']['held']);
    }

    public function test_proposals_in_avoid_category_are_held(): void
    {
        // 3+ give_backs in same category → avoid_categories includes it → held
        $history = array_fill(0, 3, $this->outcome('give_back', 'bug-hunt'));

        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('p1', 'bug-hunt', 0.85)],
            $history,
        );

        $this->assertSame([], $r['next_wave_decisions']['accept']);
        $this->assertCount(1, $r['next_wave_decisions']['held']);
        $this->assertSame('hold', $r['next_wave_decisions']['held'][0]['decision']);
    }

    public function test_held_decisions_include_risk_level(): void
    {
        $history = array_fill(0, 3, $this->outcome('give_back', 'bug-hunt'));

        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('p1', 'bug-hunt', 0.85)],
            $history,
        );

        $this->assertArrayHasKey('risk_level', $r['next_wave_decisions']['held'][0]);
    }

    public function test_accepted_decisions_include_risk_level(): void
    {
        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('high-score', 'gate-impl', 0.90)],
            [],
        );

        $accepted = $r['next_wave_decisions']['accept'];
        $this->assertCount(1, $accepted);
        $this->assertSame('low', $accepted[0]['risk_level']);
    }

    public function test_low_score_proposal_gets_high_risk_level(): void
    {
        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('weak', 'gate-impl', 0.20)],
            [],
        );

        $accepted = $r['next_wave_decisions']['accept'];
        $this->assertNotEmpty($accepted);
        $this->assertSame('high', $accepted[0]['risk_level']);
    }

    // ── AC2: previous outcomes alter avoid_categories, signal and next_action ─

    public function test_majority_successes_produce_healthy_signal(): void
    {
        $history = array_fill(0, 6, $this->outcome('success', 'gate-impl'));

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertSame('healthy', $r['learner_feedback']['signal']);
    }

    public function test_majority_failures_produce_degraded_signal(): void
    {
        $history = [
            $this->outcome('success',   'gate-impl'),
            $this->outcome('give_back', 'bug-hunt'),
            $this->outcome('failure',   'research'),
        ];

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertSame('degraded', $r['learner_feedback']['signal']);
    }

    public function test_repeated_give_back_category_appears_in_avoid_categories(): void
    {
        $history = array_fill(0, 2, $this->outcome('give_back', 'research'));

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertContains('research', $r['learner_feedback']['avoid_categories']);
    }

    public function test_high_give_back_ratio_triggers_reduce_next_action(): void
    {
        // 5 give_backs out of 5 total → ratio = 1.0 > 0.4
        $history = array_fill(0, 5, $this->outcome('give_back', 'origination'));

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertSame('reduce_give_back_rate', $r['learner_feedback']['next_action']);
    }

    public function test_healthy_signal_triggers_escalate_next_action(): void
    {
        $history = array_fill(0, 8, $this->outcome('success', 'gate-impl'));

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertSame('continue_or_escalate_ambition', $r['learner_feedback']['next_action']);
    }

    // ── AC3: next_decision changes when outcome evidence changes ─────────────

    public function test_degraded_signal_produces_pause_and_repair_decision(): void
    {
        $history = [$this->outcome('failure', 'gate-impl'), $this->outcome('failure', 'research')];

        $r = $this->cycle()->processCycleOutcomes([], $history);

        $this->assertSame('pause_and_repair', $r['next_decision']);
    }

    public function test_clean_healthy_outcomes_produce_advance_wave_decision(): void
    {
        $history = array_fill(0, 5, $this->outcome('success', 'gate-impl'));

        $r = $this->cycle()->processCycleOutcomes(
            [$this->proposal('p1', 'gate-impl', 0.85)],
            $history,
        );

        $this->assertSame('advance_wave', $r['next_decision']);
    }

    public function test_next_decision_changes_as_outcome_evidence_changes(): void
    {
        $proposals = [$this->proposal('p1', 'gate-impl', 0.85)];

        $healthyOutcomes  = array_fill(0, 6, $this->outcome('success', 'gate-impl'));
        $degradedOutcomes = array_fill(0, 5, $this->outcome('failure', 'gate-impl'));

        $r1 = $this->cycle()->processCycleOutcomes($proposals, $healthyOutcomes);
        $r2 = $this->cycle()->processCycleOutcomes($proposals, $degradedOutcomes);

        $this->assertNotSame($r1['next_decision'], $r2['next_decision']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_process_cycle_outcomes_is_deterministic(): void
    {
        $proposals = [$this->proposal('p1', 'gate-impl', 0.80)];
        $outcomes  = array_fill(0, 3, $this->outcome('success', 'gate-impl'));

        $a = $this->cycle()->processCycleOutcomes($proposals, $outcomes);
        $b = $this->cycle()->processCycleOutcomes($proposals, $outcomes);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
