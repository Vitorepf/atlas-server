<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autopoiesis;

use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisHypothesisGenerator;
use Tests\TestCase;

final class AtlasSelfConstructionAutopoiesisHypothesisGeneratorTest extends TestCase
{
    public function test_generates_hypotheses_from_each_evidence_type(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'failures' => [['organ' => 'verification_court', 'class' => 'flaky_test', 'evidence_refs' => ['receipt:r1']]],
            'give_backs' => [['organ' => 'maestro', 'class' => 'missing_extractor', 'evidence_refs' => ['receipt:r2']]],
            'gaps' => [['organ' => 'cortex', 'capability_gap' => 'memory_retrieval']],
            'repeated_friction' => [['organ' => 'worker_swarm', 'kind' => 'race_on_lease', 'count' => 3]],
        ]);

        $organs = array_column($verdict['hypotheses'], 'target_organ');
        $this->assertContains('verification_court', $organs);
        $this->assertContains('maestro', $organs);
        $this->assertContains('cortex', $organs);
        $this->assertContains('worker_swarm', $organs);
        foreach ($verdict['hypotheses'] as $h) {
            foreach (['target_organ', 'class', 'expected_leverage', 'safety_boundary', 'required_evidence'] as $key) {
                $this->assertArrayHasKey($key, $h);
            }
        }
    }

    public function test_failure_without_evidence_refs_is_skipped(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'failures' => [['organ' => 'verification_court', 'class' => 'flaky_test', 'evidence_refs' => []]],
        ]);

        $this->assertSame([], $verdict['hypotheses']);
    }

    public function test_proxy_only_seed_with_no_real_evidence_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'seed_signals' => ['novelty', 'task_count'],
        ]);

        $this->assertSame([], $verdict['hypotheses']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertStringContainsString('proxy_only_seed', $verdict['rejected'][0]['reason']);
    }

    public function test_proxy_seed_with_real_failure_still_generates(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'seed_signals' => ['novelty'],
            'failures' => [['organ' => 'cortex', 'class' => 'recall_miss', 'evidence_refs' => ['receipt:r1']]],
        ]);

        $this->assertCount(1, $verdict['hypotheses']);
        $this->assertSame([], $verdict['rejected']);
    }

    public function test_repeated_friction_below_count_2_is_ignored(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'repeated_friction' => [['organ' => 'worker_swarm', 'kind' => 'race_on_lease', 'count' => 1]],
        ]);

        $this->assertSame([], $verdict['hypotheses']);
    }

    public function test_generation_is_deterministic_byte_identical(): void
    {
        $input = [
            'failures' => [['organ' => 'verification_court', 'class' => 'flaky_test', 'evidence_refs' => ['r1']]],
            'gaps' => [['organ' => 'cortex', 'capability_gap' => 'recall']],
        ];
        $svc = new AtlasSelfConstructionAutopoiesisHypothesisGenerator;
        $this->assertSame(json_encode($svc->generate($input)), json_encode($svc->generate($input)));
    }

    public function test_expected_leverage_is_a_fact_vector_never_a_scalar(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'failures' => [['organ' => 'a', 'class' => 'b', 'evidence_refs' => ['r1']]],
        ]);

        $this->assertIsArray($verdict['hypotheses'][0]['expected_leverage'], 'expected_leverage must be a FACT vector');
        foreach ($verdict['hypotheses'][0]['expected_leverage'] as $k => $v) {
            $this->assertStringNotContainsString('score', (string) $k);
        }
    }

    public function test_duplicate_organ_class_pairs_are_suppressed(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'failures' => [
                ['organ' => 'cortex', 'class' => 'recall_miss', 'evidence_refs' => ['r1']],
                ['organ' => 'cortex', 'class' => 'recall_miss', 'evidence_refs' => ['r2']],
            ],
        ]);

        $this->assertCount(1, $verdict['hypotheses']);
        $this->assertSame('cortex', $verdict['hypotheses'][0]['target_organ']);
    }

    public function test_hypotheses_are_ranked_by_impact_score_then_organ(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'failures' => [
                ['organ' => 'z_organ', 'class' => 'alpha', 'evidence_refs' => ['r1']],
                ['organ' => 'a_organ', 'class' => 'alpha', 'evidence_refs' => ['r2']],
            ],
        ]);

        // Equal impact → alphabetical by target_organ (a_organ < z_organ).
        $this->assertSame('a_organ', $verdict['hypotheses'][0]['target_organ']);
        $this->assertSame('z_organ', $verdict['hypotheses'][1]['target_organ']);
    }

    // ── ambition_second_pass ──────────────────────────────────────────────────

    public function test_low_yield_with_evidence_and_gaps_emits_ambition_second_pass(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => ['receipt:exhausted-1', 'receipt:exhausted-2'],
            'gaps'                     => [['organ' => 'cortex', 'capability_gap' => 'deep_reasoning']],
        ]);

        $origins = array_column($verdict['hypotheses'], 'origin');
        $this->assertContains('ambition_second_pass', $origins);
    }

    public function test_ambition_hypothesis_has_all_required_fields(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => ['receipt:exhausted-1'],
            'gaps'                     => [['organ' => 'maestro', 'capability_gap' => 'multi_step_planning']],
        ]);

        $ambition = array_values(array_filter($verdict['hypotheses'], fn ($h) => $h['origin'] === 'ambition_second_pass'));
        $this->assertCount(1, $ambition);
        $h = $ambition[0];

        foreach (['target_organ', 'class', 'expected_leverage', 'safety_boundary', 'required_evidence', 'supporting_refs'] as $key) {
            $this->assertArrayHasKey($key, $h, "ambition hypothesis missing key: $key");
        }
        $this->assertSame('maestro', $h['target_organ']);
        $this->assertStringContainsString('ambition_second_pass', $h['class']);
        $this->assertIsArray($h['expected_leverage']);
        $this->assertIsArray($h['required_evidence']);
        $this->assertIsArray($h['supporting_refs']);
        $this->assertContains('receipt:exhausted-1', $h['supporting_refs']);
    }

    public function test_ambition_expected_leverage_is_fact_vector_not_scalar(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => ['ref:1'],
            'gaps'                     => [['organ' => 'worker_swarm', 'capability_gap' => 'parallel_execution']],
        ]);

        $h = array_values(array_filter($verdict['hypotheses'], fn ($h) => $h['origin'] === 'ambition_second_pass'))[0];
        $this->assertIsArray($h['expected_leverage'], 'expected_leverage must be a fact vector');
        foreach (array_keys($h['expected_leverage']) as $k) {
            $this->assertStringNotContainsString('score', (string) $k);
        }
    }

    public function test_low_yield_without_evidence_refs_is_rejected_as_proxy_only(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => [],   // no real refs
            'gaps'                     => [['organ' => 'cortex', 'capability_gap' => 'recall']],
        ]);

        $ambition = array_filter($verdict['hypotheses'], fn ($h) => $h['origin'] === 'ambition_second_pass');
        $this->assertSame([], array_values($ambition), 'no ambition hypotheses without evidence');
        $reasons = array_column($verdict['rejected'], 'reason');
        $this->assertNotEmpty(array_filter($reasons, fn ($r) => str_contains($r, 'proxy_only_seed')));
    }

    public function test_low_yield_with_evidence_but_no_gaps_emits_no_ambition_hypotheses(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => ['ref:1'],
            // no gaps
        ]);

        $ambition = array_filter($verdict['hypotheses'], fn ($h) => $h['origin'] === 'ambition_second_pass');
        $this->assertSame([], array_values($ambition));
        $this->assertSame([], $verdict['rejected']);
    }

    public function test_ambition_second_pass_coexists_with_failure_hypotheses(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisHypothesisGenerator)->generate([
            'low_yield'                => true,
            'exhausted_surface_evidence' => ['ref:exhausted'],
            'gaps'                     => [['organ' => 'cortex', 'capability_gap' => 'recall']],
            'failures'                 => [['organ' => 'verification_court', 'class' => 'flaky', 'evidence_refs' => ['r1']]],
        ]);

        $origins = array_column($verdict['hypotheses'], 'origin');
        $this->assertContains('failure',             $origins);
        $this->assertContains('ambition_second_pass', $origins);
    }
}
