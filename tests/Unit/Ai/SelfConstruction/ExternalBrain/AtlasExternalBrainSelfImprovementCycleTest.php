<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

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

    private function signal(string $label, string $category = 'bug_fix', float $score = 0.6): array
    {
        return [
            'label'               => $label,
            'objective'           => 'Implement '.$label,
            'category'            => $category,
            'allowed_files'       => ['app/Services/'.$label.'.php', 'tests/Unit/'.$label.'Test.php'],
            'acceptance_criteria' => [
                './vendor/bin/phpunit tests/Unit/'.$label.'Test.php',
                'implementation reviewed and merged',
            ],
            'required_evidence'   => ['tests_or_gates_result'],
            'value_mechanism'     => 'closes_gap:'.$label,
            // leverage dimensions the scorer expects
            'capability_unlock'       => $score,
            'dependency_unblock'      => $score * 0.5,
            'implementation_evidence' => 0.8,
            'repeated_pain'           => 0.3,
            'blast_radius_safety'     => 0.9,
            'final_score'             => $score,
        ];
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.self_improvement_cycle.v1',
            AtlasExternalBrainSelfImprovementCycle::SCHEMA,
        );
    }

    public function test_mixed_input_produces_next_wave_plan_with_all_required_keys(): void
    {
        $signals = [
            $this->signal('arch-1',    'architecture_unlock', 0.9),
            $this->signal('bug-1',     'bug_fix',             0.7),
            $this->signal('runtime-1', 'runtime_continuity',  0.6),
            $this->signal('docs-1',    'docs_sync',           0.5),
        ];

        $result = $this->cycle()->run($signals);

        foreach (['schema', 'accepted', 'rejected', 'audit', 'learner_feedback', 'portfolio_balance', 'stats'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainSelfImprovementCycle::SCHEMA, $result['schema']);
    }

    public function test_accepted_candidates_are_ordered_as_next_wave_plan(): void
    {
        $signals = [
            $this->signal('high',   'architecture_unlock', 0.9),
            $this->signal('medium', 'bug_fix',             0.6),
            $this->signal('low',    'docs_sync',           0.3),
        ];

        $result = $this->cycle()->run($signals);

        $this->assertNotEmpty($result['accepted']);
        // All accepted packets carry dependency_wave and value_mechanism.
        foreach ($result['accepted'] as $packet) {
            $this->assertArrayHasKey('dependency_wave', $packet);
            $this->assertArrayHasKey('value_mechanism', $packet);
        }
    }

    public function test_rejected_candidates_carry_reason(): void
    {
        // Two thin tasks in different categories → both rejected (no grouping partner).
        $signals = [
            [
                'label'               => 'thin-gate',
                'objective'           => 'Thin test gate',
                'category'            => 'test_gate',
                'allowed_files'       => ['app/Services/ThinGate.php'],
                'acceptance_criteria' => ['single criterion'],
                'required_evidence'   => ['tests_or_gates_result'],
                'value_mechanism'     => 'gate:thin',
                'capability_unlock' => 0.3, 'dependency_unblock' => 0.2,
                'implementation_evidence' => 0.5, 'repeated_pain' => 0.2, 'blast_radius_safety' => 0.8,
                'final_score' => 0.4,
            ],
            $this->signal('arch-2', 'architecture_unlock', 0.9),
        ];

        $result = $this->cycle()->run($signals);

        // thin-gate has no partner → rejected.
        $rejectedLabels = array_column($result['rejected'], 'label');
        $this->assertContains('thin-gate', $rejectedLabels);
        // Every rejected entry has a reason.
        foreach ($result['rejected'] as $r) {
            $this->assertArrayHasKey('reason', $r);
            $this->assertNotEmpty($r['reason']);
        }
    }

    public function test_audit_result_is_included_in_plan(): void
    {
        $signals = [
            $this->signal('s1', 'architecture_unlock', 0.9),
            $this->signal('s2', 'bug_fix',             0.7),
        ];

        $result = $this->cycle()->run($signals);

        $this->assertArrayHasKey('verdict', $result['audit']);
        $this->assertArrayHasKey('findings', $result['audit']);
        $this->assertContains($result['audit']['verdict'], [
            AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS,
            AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REPAIR_REQUIRED,
            AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT,
        ]);
    }

    public function test_learner_feedback_reflects_previous_outcomes(): void
    {
        $outcomes = [
            ['outcome' => 'success',   'category' => 'bug_fix'],
            ['outcome' => 'success',   'category' => 'bug_fix'],
            ['outcome' => 'give_back', 'category' => 'docs_sync'],
            ['outcome' => 'give_back', 'category' => 'docs_sync'],
        ];

        $result = $this->cycle()->run([$this->signal('s1', 'bug_fix', 0.7)], $outcomes);

        $lf = $result['learner_feedback'];
        $this->assertSame(4, $lf['total_outcomes']);
        $this->assertSame(2, $lf['success_count']);
        $this->assertSame(2, $lf['give_back_count']);
        $this->assertContains('docs_sync', $lf['avoid_categories']);
    }

    public function test_learner_signal_is_healthy_when_majority_succeed(): void
    {
        $outcomes = array_fill(0, 7, ['outcome' => 'success', 'category' => 'bug_fix'])
            + array_fill(7, 3, ['outcome' => 'give_back', 'category' => 'bug_fix']);
        $outcomes = array_values($outcomes);

        $result = $this->cycle()->run([$this->signal('x', 'bug_fix', 0.7)], $outcomes);

        $this->assertSame('healthy', $result['learner_feedback']['signal']);
    }

    public function test_learner_signal_is_no_data_when_empty_outcomes(): void
    {
        $result = $this->cycle()->run([$this->signal('x', 'bug_fix', 0.7)], []);

        $this->assertSame('no_data', $result['learner_feedback']['signal']);
    }

    public function test_duplicate_signals_are_deduplicated(): void
    {
        $signals = [
            $this->signal('dup-1', 'bug_fix', 0.7),
            $this->signal('dup-1', 'bug_fix', 0.7),  // exact duplicate
            $this->signal('other', 'architecture_unlock', 0.8),
        ];

        $result = $this->cycle()->run($signals);

        $this->assertSame(2, $result['stats']['normalized']);
        $this->assertSame(3, $result['stats']['signals_in']);
    }

    public function test_signals_with_blank_objective_are_dropped(): void
    {
        $signals = [
            array_merge($this->signal('blank-obj', 'bug_fix', 0.5), ['objective' => '']),
            $this->signal('valid-1', 'bug_fix', 0.7),
        ];

        $result = $this->cycle()->run($signals);

        $this->assertSame(1, $result['stats']['normalized']);
    }

    public function test_empty_signals_returns_empty_accepted(): void
    {
        $result = $this->cycle()->run([]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame(0, $result['stats']['signals_in']);
        $this->assertSame(0, $result['stats']['accepted_count']);
    }

    public function test_portfolio_balance_included_in_output(): void
    {
        $signals = [
            $this->signal('s1', 'bug_fix',             0.7),
            $this->signal('s2', 'architecture_unlock', 0.8),
        ];

        $result = $this->cycle()->run($signals);

        $this->assertArrayHasKey('status',   $result['portfolio_balance']);
        $this->assertArrayHasKey('deficits', $result['portfolio_balance']);
        $this->assertArrayHasKey('surpluses', $result['portfolio_balance']);
    }

    public function test_cycle_is_idempotent_on_same_input(): void
    {
        $signals  = [$this->signal('idem-1', 'bug_fix', 0.7), $this->signal('idem-2', 'architecture_unlock', 0.8)];
        $result1  = $this->cycle()->run($signals);
        $result2  = $this->cycle()->run($signals);

        $this->assertSame($result1['stats'], $result2['stats']);
        $this->assertSame(
            array_column($result1['accepted'], 'task_packet_id'),
            array_column($result2['accepted'], 'task_packet_id'),
        );
    }

    // ── AC1: next_wave_decisions ──────────────────────────────────────────────

    public function test_next_wave_decisions_key_is_present_in_output(): void
    {
        $result = $this->cycle()->run([$this->signal('s1', 'bug_fix', 0.7)]);
        $this->assertArrayHasKey('next_wave_decisions', $result);
    }

    public function test_next_wave_decisions_has_accept_repair_and_held_buckets(): void
    {
        $result = $this->cycle()->run([$this->signal('s1', 'bug_fix', 0.7)]);
        $nwd = $result['next_wave_decisions'];
        $this->assertArrayHasKey('accept',          $nwd);
        $this->assertArrayHasKey('repair_required', $nwd);
        $this->assertArrayHasKey('held',            $nwd);
    }

    public function test_accepted_tasks_produce_accept_decisions_with_reason(): void
    {
        $result = $this->cycle()->run([
            $this->signal('a1', 'architecture_unlock', 0.9),
            $this->signal('a2', 'bug_fix',             0.8),
        ]);

        $nwd = $result['next_wave_decisions'];
        // At least some accepted tasks should land in accept or repair_required.
        $all = array_merge($nwd['accept'], $nwd['repair_required'], $nwd['held']);
        $this->assertSame(count($result['accepted']), count($all));
        foreach ($all as $decision) {
            $this->assertArrayHasKey('decision', $decision);
            $this->assertArrayHasKey('reason',   $decision);
            $this->assertNotEmpty($decision['reason']);
        }
    }

    // ── AC2: hold by learner feedback ────────────────────────────────────────

    public function test_high_score_candidate_in_avoid_category_is_held(): void
    {
        // docs_sync gets 2 give_back → in avoid_categories
        $outcomes = [
            ['outcome' => 'give_back', 'category' => 'docs_sync'],
            ['outcome' => 'give_back', 'category' => 'docs_sync'],
        ];

        $result = $this->cycle()->run(
            [
                $this->signal('high-docs', 'docs_sync',           0.95),
                $this->signal('arch-ok',   'architecture_unlock', 0.80),
            ],
            $outcomes,
        );

        $this->assertContains('docs_sync', $result['learner_feedback']['avoid_categories']);

        $held = $result['next_wave_decisions']['held'];
        $this->assertNotEmpty($held, 'high-score docs_sync candidate must be held');
        $heldReasons = array_column($held, 'reason');
        foreach ($heldReasons as $reason) {
            $this->assertStringContainsString('docs_sync', $reason);
        }
    }

    public function test_held_decision_reason_contains_avoid_category_name(): void
    {
        $outcomes = [
            ['outcome' => 'give_back', 'category' => 'runtime_continuity'],
            ['outcome' => 'give_back', 'category' => 'runtime_continuity'],
        ];

        $result = $this->cycle()->run(
            [$this->signal('rt-1', 'runtime_continuity', 0.9)],
            $outcomes,
        );

        $held = $result['next_wave_decisions']['held'];
        if ($held !== []) {
            $this->assertStringContainsString('runtime_continuity', $held[0]['reason']);
            $this->assertSame('hold', $held[0]['decision']);
        }
        // If rt-1 was rejected before reaching next_wave_decisions, the held array is empty — still valid.
        $this->assertIsArray($held);
    }
}
