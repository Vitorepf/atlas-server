<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphQualityRunner;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphQualityRunnerTest extends TestCase
{
    public function test_dead_leaf_task_is_advised_for_pruning(): void
    {
        $runner = new AtlasExternalBrainTaskGraphQualityRunner;

        $result = $runner->run([
            'tasks_for_pruning' => [
                ['task_id' => 'leaf-1', 'has_downstream_unlocks' => false, 'leverage_score' => 0.1,
                 'evidence_value' => 0.2, 'maturity_gap_coverage' => 0.1, 'is_safety_or_certification' => false,
                 'duplication_risk' => 0.0],
            ],
        ]);

        $this->assertNotEmpty($result['prune_recommendations']);
        $prune = $result['prune_recommendations'][0];
        $this->assertStringContainsString('leaf', $prune['recommendation'] ?? '',
            'a dead leaf must get a pruning recommendation (retire/delay/merge)');
    }

    public function test_batch_is_counterfactually_reviewed_with_causal_attribution(): void
    {
        $runner = new AtlasExternalBrainTaskGraphQualityRunner;

        $result = $runner->run([
            'tasks_for_pruning' => [],
            'outcomes' => [
                ['task_packet_id' => 't1', 'outcome' => 'give_back', 'spec' => ['quality_score' => 0.8, 'acceptance_criteria' => ['AC1']],
                 'worker' => ['task_class' => 'bugfix', 'avoid_task_classes' => ['bugfix']]],
            ],
            'proposed_batch' => [
                ['task_packet_id' => 'a', 'leverage_score' => 0.8, 'implementation_readiness' => 0.9],
                ['task_packet_id' => 'b', 'leverage_score' => 0.7, 'implementation_readiness' => 0.8],
            ],
            'counterfactual_batch' => [
                ['task_packet_id' => 'a', 'leverage_score' => 0.8, 'implementation_readiness' => 0.9],
            ],
        ]);

        // Causal attribution for give_back outcome should return a cause
        $this->assertNotEmpty($result['causal_attributions']);
        $this->assertNotEmpty($result['counterfactual_review']);
    }

    public function test_runner_returns_schema(): void
    {
        $result = (new AtlasExternalBrainTaskGraphQualityRunner)->run([]);

        $this->assertSame(
            AtlasExternalBrainTaskGraphQualityRunner::SCHEMA,
            $result['schema'],
        );
        $this->assertSame([], $result['prune_recommendations']);
        $this->assertSame([], $result['causal_attributions']);
        $this->assertNull($result['mutation_test']);
        $this->assertNull($result['counterfactual_review']);
    }
}
