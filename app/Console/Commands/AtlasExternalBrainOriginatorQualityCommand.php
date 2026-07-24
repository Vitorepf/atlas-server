<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAcceptanceReplayCoverageMatrix;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialCritiqueTournament;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierComplexityBudget;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierControlPlane;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierEndToEndTrial;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierFallbackRunbook;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyDependencyInverter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyRegressionOracle;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyRegressionSentinel;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBlindSpotCurriculum;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityRubric;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCausalAblationBatchStudy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitionCascadeController;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitiveWorkPartitioner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossModelConsensusNormalizer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationFirstCircuitBreaker;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContextBudgetDistiller;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCostQualityParetoFront;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCritiqueQuorumReducer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossProjectEvolutionProfile;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageScorer;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only originator-quality runtime. Pipes candidate opportunities
 * through four pure gates, in order:
 *
 *   1. {@see AtlasExternalBrainAdversarialCritiqueTournament} — five+ critique
 *      lenses reject proxy work, operator dependency, duplicate/overwide
 *      targets and template-farm shape BEFORE anything is scored.
 *   2. {@see AtlasExternalBrainLeverageScorer} — survivors are ranked by
 *      compounding leverage, not ease.
 *   3. {@see AtlasExternalBrainAcceptanceReplayCoverageMatrix} — each ranked
 *      candidate must carry a runnable command, real implementation-file
 *      coverage, and evidence for any claimed leverage dimension.
 *   4. {@see AtlasExternalBrainHighValueBatchComposer} — the fully-vetted,
 *      ranked survivors are composed into one bounded, wave-ordered batch
 *      instead of quota padding.
 *
 * Never enqueues, mutates the queue, or calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { opportunities:list, max_batch?:int }
 * Each opportunity entry carries fields for ALL four stages simultaneously
 * (objective, allowed_files, acceptance_criteria, required_evidence,
 * value_mechanism, category, label, evidence_refs, plus the leverage-scoring
 * dimensions) — each stage reads only the keys it understands.
 */
final class AtlasExternalBrainOriginatorQualityCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:originator-quality
        {--input= : Path to a JSON file with opportunities and optional max_batch}';

    /** @var string */
    protected $description = 'Read-only originator-quality runtime: critique → leverage-rank → coverage-audit → bounded high-value batch.';

    public function handle(
        AtlasExternalBrainAdversarialCritiqueTournament $tournament,
        AtlasExternalBrainLeverageScorer $scorer,
        AtlasExternalBrainAcceptanceReplayCoverageMatrix $coverageMatrix,
        AtlasExternalBrainHighValueBatchComposer $batchComposer,
        AtlasExternalBrainAmplifierComplexityBudget $complexityBudget,
        AtlasExternalBrainAmplifierControlPlane $amplifierControlPlane,
        AtlasExternalBrainAmplifierEndToEndTrial $endToEndTrial,
        AtlasExternalBrainAmplifierFallbackRunbook $fallbackRunbook,
        AtlasExternalBrainAutonomyDependencyInverter $dependencyInverter,
        AtlasExternalBrainAutonomyRegressionOracle $regressionOracle,
        AtlasExternalBrainAutonomyRegressionSentinel $regressionSentinel,
        AtlasExternalBrainBlindSpotCurriculum $blindSpotCurriculum,
        AtlasExternalBrainCapabilityRubric $capabilityRubric,
        AtlasExternalBrainCausalAblationBatchStudy $causalAblationBatchStudy,
        AtlasExternalBrainCognitiveWorkPartitioner $cognitiveWorkPartitioner,
        AtlasExternalBrainConsolidationFirstCircuitBreaker $consolidationFirstCircuitBreaker,
        AtlasExternalBrainContextBudgetDistiller $contextBudgetDistiller,
        AtlasExternalBrainCrossModelConsensusNormalizer $consensusNormalizer,
        AtlasExternalBrainCritiqueQuorumReducer $critiqueQuorumReducer,
        AtlasExternalBrainCostQualityParetoFront $costQualityParetoFront,
        AtlasExternalBrainCognitionCascadeController $cognitionCascadeController,
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

        $opportunities = is_array($decoded['opportunities'] ?? null) ? array_values($decoded['opportunities']) : [];
        $maxBatch = isset($decoded['max_batch']) ? (int) $decoded['max_batch'] : AtlasExternalBrainHighValueBatchComposer::DEFAULT_MAX_BATCH;

        $critique = $tournament->run(['packets' => $opportunities]);

        $blockedIndices = [];
        foreach ($critique['blocking_findings'] as $finding) {
            if (isset($finding['packet_index'])) {
                $blockedIndices[(int) $finding['packet_index']] = true;
            }
            foreach ((array) ($finding['packet_indices'] ?? []) as $idx) {
                $blockedIndices[(int) $idx] = true;
            }
        }

        $survivors = [];
        foreach ($opportunities as $i => $opp) {
            if (! isset($blockedIndices[$i]) && is_array($opp)) {
                $survivors[] = $opp;
            }
        }

        // rank() returns scoring fields only (label, final_score, ...) — merge the original
        // opportunity fields back in by label so downstream stages still see objective,
        // allowed_files, acceptance_criteria, required_evidence, value_mechanism, category.
        $survivorsByLabel = [];
        foreach ($survivors as $opp) {
            $survivorsByLabel[(string) ($opp['label'] ?? '')] = $opp;
        }
        $ranked = array_map(
            static fn (array $scored): array => array_merge($survivorsByLabel[$scored['label']] ?? [], $scored),
            $scorer->rank($survivors),
        );

        $coverageRejections = [];
        $coverageApproved = [];
        foreach ($ranked as $opp) {
            $audit = $coverageMatrix->audit($opp);
            if ($audit['verdict'] === AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED) {
                $coverageRejections[] = [
                    'label' => $opp['label'] ?? '',
                    'rejections' => $audit['rejections'],
                ];

                continue;
            }
            $coverageApproved[] = $opp;
        }

        $batch = $batchComposer->compose($coverageApproved, ['max_batch' => $maxBatch]);

        $payload = [
            'status' => 'ok',
            'critique_blocking' => $critique['blocking'],
            'critique_winning_attack' => $critique['winning_attack'],
            'critique_blocking_findings' => $critique['blocking_findings'],
            'critique_rejected_count' => count($blockedIndices),
            'leverage_ranked_count' => count($ranked),
            'coverage_rejections' => $coverageRejections,
            'emitted_batch' => $batch['emitted'],
            'batch_rejected' => $batch['rejected'],
            'batch_stats' => $batch['stats'],
            'batch_thesis' => $batch['batch_thesis'],
            'wave_plan' => $batch['wave_plan'],
        ];

        // Optional system-wide amplifier complexity-budget check: this is a distinct
        // concern from per-opportunity coverage auditing above (component/gate/judge/
        // telemetry budgets vs. per-candidate acceptance replay), so it is only run
        // when the caller explicitly supplies a complexity_budget section.
        if (is_array($decoded['complexity_budget'] ?? null)) {
            $payload['complexity_budget'] = $complexityBudget->evaluate($decoded['complexity_budget']);
        }

        // Optional amplifier control-plane mode decision: a distinct concern from the
        // complexity budget above (origination mode selection vs. system-wide budget),
        // so it is only run when the caller explicitly supplies an amplifier_control_plane section.
        if (is_array($decoded['amplifier_control_plane'] ?? null)) {
            $payload['amplifier_control_plane'] = $amplifierControlPlane->decide($decoded['amplifier_control_plane']);
        }

        // Optional amplifier end-to-end trial: benchmarks small/scaffolded/frontier tiers
        // against supplied facts and recommends a tier. Distinct from the mode decision
        // above, so it only runs when the caller explicitly supplies an
        // amplifier_end_to_end_trial section.
        if (is_array($decoded['amplifier_end_to_end_trial'] ?? null)) {
            $payload['amplifier_end_to_end_trial'] = $endToEndTrial->run($decoded['amplifier_end_to_end_trial']);
        }

        // Optional amplifier fallback runbook: compiles the strengthening-step sequence and
        // escalation decision for a small-model run. Distinct from the tier trial above, so
        // it only runs when the caller explicitly supplies an amplifier_fallback_runbook section.
        if (is_array($decoded['amplifier_fallback_runbook'] ?? null)) {
            $payload['amplifier_fallback_runbook'] = $fallbackRunbook->compile($decoded['amplifier_fallback_runbook']);
        }

        // Optional autonomy dependency inversion: proposes Atlas-native replacements for
        // human/operator/provider dependencies still active in steady state. Distinct from
        // the fallback runbook above, so it only runs when the caller explicitly supplies an
        // autonomy_dependency_inversion section.
        if (is_array($decoded['autonomy_dependency_inversion'] ?? null)) {
            $payload['autonomy_dependency_inversion'] = $dependencyInverter->invert($decoded['autonomy_dependency_inversion']);
        }

        // Optional autonomy regression assessment: compares before/after capability
        // snapshots for a proposed change. Distinct from the dependency inversion above
        // (regression detection vs. replacement proposal), so it only runs when the caller
        // explicitly supplies an autonomy_regression_assessment section.
        if (is_array($decoded['autonomy_regression_assessment'] ?? null)) {
            $payload['autonomy_regression_assessment'] = $regressionOracle->assess($decoded['autonomy_regression_assessment']);
        }

        // Optional autonomy regression sentinel scan: detects whether a proposed change
        // reintroduces human/operator/provider dependency into the steady-state design.
        // Distinct from the delta-based oracle assessment above (single-state contract scan
        // vs. before/after delta), so it only runs when the caller explicitly supplies an
        // autonomy_regression_scan section.
        if (is_array($decoded['autonomy_regression_scan'] ?? null)) {
            $payload['autonomy_regression_scan'] = $regressionSentinel->scan($decoded['autonomy_regression_scan']);
        }

        // Optional blind-spot curriculum: promotes repeated failure modes into challenge
        // cases/preflight checks (build) and ranks blind spots by future quality lift
        // (rankLearningItems). Distinct from the regression checks above (learning-curriculum
        // synthesis vs. regression detection), so it only runs when the caller explicitly
        // supplies a blind_spot_curriculum section.
        if (is_array($decoded['blind_spot_curriculum'] ?? null)) {
            $curriculumInput = $decoded['blind_spot_curriculum'];
            $curriculumOutput = [];
            if (isset($curriculumInput['failure_observations'])) {
                $curriculumOutput['curriculum'] = $blindSpotCurriculum->build($curriculumInput);
            }
            if (isset($curriculumInput['blind_spots'])) {
                $curriculumOutput['ranked_learning_items'] = $blindSpotCurriculum->rankLearningItems($curriculumInput);
            }
            $payload['blind_spot_curriculum'] = $curriculumOutput;
        }

        // Optional capability rubric evaluation: scores external-brain capability against
        // the 8-dimension 95% target rubric with hard-fail gates. Distinct from the
        // curriculum above (capability scoring vs. failure-pattern learning), so it only
        // runs when the caller explicitly supplies a capability_rubric section.
        if (is_array($decoded['capability_rubric'] ?? null)) {
            $rubricInput = $decoded['capability_rubric'];
            $dimensionScores = is_array($rubricInput['dimension_scores'] ?? null) ? $rubricInput['dimension_scores'] : [];
            $triggeredGates = is_array($rubricInput['triggered_gates'] ?? null) ? $rubricInput['triggered_gates'] : [];
            $payload['capability_rubric'] = $capabilityRubric->evaluate($dimensionScores, $triggeredGates);
        }

        // Optional causal ablation batch study: correlates batch-design dimensions against
        // outcome dimensions to find likely causes of value/failure. Distinct from the
        // capability rubric above (causal inference over historical batches vs. static
        // dimension scoring), so it only runs when the caller explicitly supplies a
        // causal_ablation_batch_study section.
        if (is_array($decoded['causal_ablation_batch_study'] ?? null)) {
            $payload['causal_ablation_batch_study'] = $causalAblationBatchStudy->study($decoded['causal_ablation_batch_study']);
        }

        // Optional causal ablation control/treatment compare: decides keep/rollback/collect-more
        // for a single policy change given control and treatment batch metrics. Distinct from the
        // aggregate batch study above (one contrast vs. many dimensions), so it only runs when the
        // caller explicitly supplies both control and treatment.
        if (is_array($decoded['causal_ablation_compare']['control'] ?? null)
            && is_array($decoded['causal_ablation_compare']['treatment'] ?? null)) {
            $payload['causal_ablation_compare'] = $causalAblationBatchStudy->compare(
                $decoded['causal_ablation_compare']['control'],
                $decoded['causal_ablation_compare']['treatment'],
            );
        }

        // Optional consolidation-first circuit breaker: decides whether the brain should
        // consolidate/simplify before adding more organs. Distinct from the causal ablation
        // compare above (sprawl-signal gating vs. control/treatment contrast), so it only
        // runs when the caller explicitly supplies a consolidation_first section.
        if (is_array($decoded['consolidation_first'] ?? null)) {
            $payload['consolidation_first'] = $consolidationFirstCircuitBreaker->evaluate($decoded['consolidation_first']);
        }

        // Optional cognitive work partitioner: splits a mission into deterministic cognitive
        // phases with model-tier hints. Distinct from the consolidation-first gate above (mission
        // phase planning vs. sprawl gating), so it only runs when the caller explicitly supplies
        // a cognitive_work_partitioner section.
        if (is_array($decoded['cognitive_work_partitioner'] ?? null)) {
            $payload['cognitive_work_partitioner'] = $cognitiveWorkPartitioner->partition($decoded['cognitive_work_partitioner']);
        }

        // Optional context budget distillation: compacts a full context pack into a
        // budget-constrained high-signal subset. Distinct from the consolidation-first
        // gate above (context compaction vs. sprawl gating), so it only runs when the
        // caller explicitly supplies a context_budget_distiller section.
        if (is_array($decoded['context_budget_distiller'] ?? null)) {
            $payload['context_budget_distiller'] = $contextBudgetDistiller->distill($decoded['context_budget_distiller']);
        }

        // Optional cross-project evolution profile: builds the portable autonomy/evidence/lane
        // profile for the target project (canonical Atlas or an external project). Distinct from
        // the context distiller above (project transfer profile vs. context compaction), so it
        // only runs when the caller explicitly supplies a cross_project_evolution_profile section.
        if (is_array($decoded['cross_project_evolution_profile'] ?? null)) {
            $profileInput = $decoded['cross_project_evolution_profile'];
            $profile = (bool) ($profileInput['is_canonical_atlas'] ?? false)
                ? AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas()
                : AtlasExternalBrainCrossProjectEvolutionProfile::forProject(
                    (string) ($profileInput['project_id'] ?? ''),
                    $profileInput,
                );
            $payload['cross_project_evolution_profile'] = $profile->toArray();
        }

        // Optional cross-model consensus normalization: merges proposals from multiple
        // models/scaffolds by evidence quality rather than naive majority vote. Distinct from
        // the cross-project evolution profile above (multi-source proposal merge vs. single
        // project's lane profile), so it only runs when the caller explicitly supplies a
        // cross_model_consensus section.
        if (is_array($decoded['cross_model_consensus'] ?? null)) {
            $payload['cross_model_consensus'] = $consensusNormalizer->normalize($decoded['cross_model_consensus']);
        }

        // Optional critique quorum reduction: collapses many critique outputs into a
        // deduplicated set of blocking findings, tradeoffs and repair actions. Distinct from
        // the cross-model consensus above (multi-critic finding reduction vs. multi-proposal
        // merge), so it only runs when the caller explicitly supplies a critique_quorum section.
        if (is_array($decoded['critique_quorum'] ?? null)) {
            $payload['critique_quorum'] = $critiqueQuorumReducer->reduce($decoded['critique_quorum']);
        }

        // Optional cost/quality Pareto front: selects model tier/scaffold/batch options on a
        // cost-quality Pareto front instead of always paying for the most expensive model.
        // Distinct from the critique quorum above (spend-vs-quality tradeoff selection vs.
        // finding reduction), so it only runs when the caller explicitly supplies a
        // cost_quality_pareto section.
        if (is_array($decoded['cost_quality_pareto'] ?? null)) {
            $payload['cost_quality_pareto'] = $costQualityParetoFront->compute($decoded['cost_quality_pareto']);
        }

        // Optional cognition cascade control: selects the minimum-cost cheap-to-expensive
        // cognition path (and/or the read_state→...→feedback admission cascade) that satisfies
        // all safety invariants. Distinct from the cost/quality Pareto front above (stage-path
        // selection vs. discrete option selection), so it only runs when the caller explicitly
        // supplies a cognition_cascade section.
        if (is_array($decoded['cognition_cascade'] ?? null)) {
            $cascadeInput = $decoded['cognition_cascade'];
            $cascadeOutput = [];
            if (isset($cascadeInput['cascade_plan'])) {
                $cascadeOutput['cascade_plan'] = $cognitionCascadeController->cascadePlan($cascadeInput['cascade_plan']);
            }
            if (isset($cascadeInput['control'])) {
                $cascadeOutput['control'] = $cognitionCascadeController->control($cascadeInput['control']);
            }
            $payload['cognition_cascade'] = $cascadeOutput;
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
