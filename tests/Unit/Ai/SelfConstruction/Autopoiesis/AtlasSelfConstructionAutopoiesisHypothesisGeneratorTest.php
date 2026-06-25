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
}
