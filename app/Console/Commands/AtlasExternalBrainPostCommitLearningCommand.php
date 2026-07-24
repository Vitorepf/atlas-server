<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityTransferMapper;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitGreenLiftEvaluator;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitToRoadmapDeltaMapper;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCounterfactualBatchEvaluator;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitLearningFeedbackRouter;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only post-commit learning loop. Composes
 * {@see AtlasExternalBrainPostCommitLearningFeedbackRouter} (lessons/warnings/constraints per
 * commit), {@see AtlasExternalBrainCommitGreenLiftEvaluator} (per-commit real-value lift + before/after
 * batch lift), {@see AtlasExternalBrainCommitToRoadmapDeltaMapper} (proven roadmap maturity delta),
 * {@see AtlasExternalBrainCapabilityTransferMapper} (what proven capability can transfer to which
 * destination gap) and {@see AtlasExternalBrainCounterfactualBatchEvaluator} (decision regret vs the
 * batch alternatives that were NOT chosen) into one next-batch learning report, so delivered commits
 * and weak outcomes change future origination policy instead of dying as passive receipts.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { commits:list, green_lift:{before:list, after:list},
 *     source_capabilities:list, destination_gaps:list, evidence_strength:object, adaptation_risks:list,
 *     counterfactual_batch:{chosen_batch:object, alternatives:list} }
 * Missing/absent sections default to empty and simply produce no findings for that side.
 */
final class AtlasExternalBrainPostCommitLearningCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.post_commit_learning.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:post-commit-learning
        {--input= : Path to a JSON file with commits, green_lift, source_capabilities, destination_gaps, evidence_strength, adaptation_risks}';

    /** @var string */
    protected $description = 'Read-only: post-commit learning loop (feedback router + green-lift evaluator + roadmap delta + capability transfer).';

    public function handle(
        AtlasExternalBrainPostCommitLearningFeedbackRouter $feedbackRouter,
        AtlasExternalBrainCommitGreenLiftEvaluator $greenLiftEvaluator,
        AtlasExternalBrainCommitToRoadmapDeltaMapper $roadmapDeltaMapper,
        AtlasExternalBrainCapabilityTransferMapper $transferMapper,
        AtlasExternalBrainCounterfactualBatchEvaluator $counterfactualEvaluator,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $commits = is_array($decoded['commits'] ?? null) ? $decoded['commits'] : [];
        $greenLift = is_array($decoded['green_lift'] ?? null) ? $decoded['green_lift'] : [];
        $sourceCapabilities = is_array($decoded['source_capabilities'] ?? null) ? $decoded['source_capabilities'] : [];
        $destinationGaps = is_array($decoded['destination_gaps'] ?? null) ? $decoded['destination_gaps'] : [];
        $evidenceStrength = is_array($decoded['evidence_strength'] ?? null) ? $decoded['evidence_strength'] : [];
        $adaptationRisks = is_array($decoded['adaptation_risks'] ?? null) ? $decoded['adaptation_risks'] : [];
        $counterfactualBatch = is_array($decoded['counterfactual_batch'] ?? null) ? $decoded['counterfactual_batch'] : [];

        $feedback = $feedbackRouter->route(['commits' => $commits]);

        $commitEvaluations = [];
        foreach ($commits as $commit) {
            if (! is_array($commit)) {
                continue;
            }
            $commitEvaluations[] = $greenLiftEvaluator->evaluateCommit($commit);
        }
        $batchLift = $greenLiftEvaluator->evaluate([
            'before' => is_array($greenLift['before'] ?? null) ? $greenLift['before'] : [],
            'after' => is_array($greenLift['after'] ?? null) ? $greenLift['after'] : [],
        ]);

        $roadmapDelta = $roadmapDeltaMapper->map(['commits' => $commits]);

        $transfer = $transferMapper->map([
            'source_capabilities' => $sourceCapabilities,
            'destination_gaps' => $destinationGaps,
            'evidence_strength' => $evidenceStrength,
            'adaptation_risks' => $adaptationRisks,
        ]);

        $counterfactualEvaluation = $counterfactualEvaluator->evaluate($counterfactualBatch);

        $payload = [
            'schema' => self::SCHEMA,
            'feedback' => $feedback,
            'commit_evaluations' => $commitEvaluations,
            'batch_lift' => $batchLift,
            'roadmap_delta' => $roadmapDelta,
            'capability_transfer' => $transfer,
            'counterfactual_evaluation' => $counterfactualEvaluation,
            'next_batch_constraints' => $feedback['next_batch_constraints'],
            'next_roadmap_gap_candidates' => $roadmapDelta['next_roadmap_gap_candidates'],
            'transfer_recommendations' => $transfer['transfer_recommendations'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
