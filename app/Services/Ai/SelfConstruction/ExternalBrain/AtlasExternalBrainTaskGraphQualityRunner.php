<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Runs the four task-graph quality organs for one queue-pressure tick:
 * prune dead leaf tasks, attribute outcomes to cause, mutation-test a spec,
 * and counterfactually review a batch. Pure composition — no I/O, no side effects.
 */
final class AtlasExternalBrainTaskGraphQualityRunner
{
    public const SCHEMA = 'atlas.external_brain.task_graph_quality_runner.v1';

    public function __construct(
        private readonly AtlasExternalBrainTaskGraphLeafPruningAdvisor $pruningAdvisor = new AtlasExternalBrainTaskGraphLeafPruningAdvisor,
        private readonly AtlasExternalBrainTaskOutcomeCausalAttributor $causalAttributor = new AtlasExternalBrainTaskOutcomeCausalAttributor,
        private readonly AtlasExternalBrainTaskSpecMutationTester $mutationTester = new AtlasExternalBrainTaskSpecMutationTester,
        private readonly AtlasExternalBrainTaskBatchCounterfactualReviewer $counterfactualReviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        // 1. Prune dead leaf tasks
        $tasksForPruning = (array) ($input['tasks_for_pruning'] ?? []);
        $pruneResults = $this->pruningAdvisor->advise([
            'tasks' => $tasksForPruning,
            'queue_pressure' => (string) ($input['queue_pressure'] ?? 'medium'),
        ]);
        $pruneRecommendations = (array) ($pruneResults['recommendations'] ?? []);

        // 2. Attribute outcomes
        $outcomes = (array) ($input['outcomes'] ?? []);
        $attributions = [];
        foreach ($outcomes as $outcome) {
            $attributions[] = $this->causalAttributor->attribute($outcome);
        }

        // 3. Mutation-test a spec
        $spec = (array) ($input['spec'] ?? []);
        $mutationResult = $spec !== [] ? $this->mutationTester->test($spec) : null;

        // 4. Counterfactual review of proposed vs counterfactual batch
        $proposedBatch = (array) ($input['proposed_batch'] ?? []);
        $counterfactualBatch = (array) ($input['counterfactual_batch'] ?? []);
        $reviewResult = $proposedBatch !== []
            ? $this->counterfactualReviewer->review($proposedBatch, $counterfactualBatch)
            : null;

        return [
            'schema' => self::SCHEMA,
            'prune_recommendations' => $pruneRecommendations,
            'causal_attributions' => $attributions,
            'mutation_test' => $mutationResult,
            'counterfactual_review' => $reviewResult,
        ];
    }
}
