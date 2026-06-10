<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor;

use App\Models\AtlasAemorJudgmentReport;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasAemorOutcome;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;

final class AtlasAemorJudgmentService
{
    public const SCHEMA_VERSION = 'atlas.aemor.judgment_report.v1';

    public function __construct(
        private readonly AtlasAemorRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function judge(string $episodeId): array
    {
        $episode = $this->runtime->episode($episodeId);
        if ($episode === null) {
            return $this->blocked('missing_episode', 'Judgment requires an existing AEMOR episode.');
        }
        $outcome = $episode->outcome()->latest()->first();
        if (! $outcome instanceof AtlasAemorOutcome) {
            return $this->blocked('missing_outcome', 'Judgment requires a closed AEMOR outcome.');
        }

        $evidenceRefs = array_values((array) ($outcome->evidence_refs ?? []));
        $causality = $this->causalityRank($outcome);
        $outcomeAttribution = $this->outcomeAttribution($outcome, $causality);
        $falseLearning = $this->falseLearningGate($outcome, $causality);
        $repeatedFailure = $this->repeatedFailureSuppression($outcome);
        $contextRoi = $this->contextRoi($outcome);
        $patchQuality = $this->patchQualityFingerprint($outcome);
        $humanCorrection = $this->humanCorrection($outcome);
        $negativeKnowledge = $this->negativeKnowledge($outcome, $repeatedFailure, $falseLearning, $causality);
        $providerSkillReliability = $this->providerSkillReliability($outcome);
        $counterfactualReplay = $this->counterfactualReplay($outcome, $causality, $contextRoi, $repeatedFailure);
        $memoryBudget = $this->memoryBudget($episode->scope_type, $episode->scope_id);
        $operationalDoctrine = $this->operationalDoctrine($outcome, $negativeKnowledge, $humanCorrection, $repeatedFailure);
        $quality = $this->qualityScore($outcome, $falseLearning, $repeatedFailure, $contextRoi);
        $memoryConflicts = $this->memoryConflicts($episode->scope_type, $episode->scope_id);
        $policyProposals = $this->policyProposals($outcome, $repeatedFailure, $falseLearning);
        $riskPrediction = $this->runtime->riskPrediction($episode->objective, [
            'scope_type' => $episode->scope_type,
            'scope_id' => $episode->scope_id,
        ]);

        $payload = [
            'episode_id' => $episode->id,
            'outcome_id' => $outcome->id,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $falseLearning['status'] === 'pass' && $repeatedFailure['status'] !== 'blocked' ? 'passed' : 'watch',
            'causality_rank' => $causality,
            'outcome_attribution' => $outcomeAttribution,
            'false_learning_gate' => $falseLearning,
            'repeated_failure_suppression' => $repeatedFailure,
            'context_roi_score' => $contextRoi,
            'patch_quality_fingerprint' => $patchQuality,
            'memory_conflicts' => $memoryConflicts,
            'human_correction' => $humanCorrection,
            'negative_knowledge' => $negativeKnowledge,
            'provider_skill_reliability' => $providerSkillReliability,
            'counterfactual_replay' => $counterfactualReplay,
            'memory_budget' => $memoryBudget,
            'operational_doctrine' => $operationalDoctrine,
            'risk_prediction' => $riskPrediction,
            'policy_proposals' => $policyProposals,
            'quality_score' => $quality,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['judgment_hash'] = MissionCanonicalHash::sha256($payload);
        $record = null;
        if (DatabaseTableAvailability::has('atlas_aemor_judgment_reports')) {
            $record = AtlasAemorJudgmentReport::query()->create($payload);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $payload['status'],
            'episode_id' => $episode->id,
            'outcome_id' => $outcome->id,
            'judgment_report_id' => $record?->id,
            'judgment_hash' => $payload['judgment_hash'],
            'causality_rank' => $causality,
            'outcome_attribution' => $outcomeAttribution,
            'false_learning_gate' => $falseLearning,
            'repeated_failure_suppression' => $repeatedFailure,
            'context_roi_score' => $contextRoi,
            'patch_quality_fingerprint' => $patchQuality,
            'memory_conflicts' => $memoryConflicts,
            'human_correction' => $humanCorrection,
            'negative_knowledge' => $negativeKnowledge,
            'provider_skill_reliability' => $providerSkillReliability,
            'counterfactual_replay' => $counterfactualReplay,
            'memory_budget' => $memoryBudget,
            'operational_doctrine' => $operationalDoctrine,
            'quality_score' => $quality,
            'policy_proposals' => $policyProposals,
            'claim_policy' => $this->runtime->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function riskPredict(string $goal, array $scope = []): array
    {
        return $this->runtime->riskPrediction($goal, $scope);
    }

    /**
     * @return array<string,mixed>
     */
    public function memoryConflicts(?string $scopeType = null, ?string $scopeId = null): array
    {
        if (! DatabaseTableAvailability::has('atlas_aemor_memory_candidates')) {
            return ['schema_version' => 'atlas.aemor.memory_conflict_resolution.v1', 'status' => 'missing', 'conflicts' => []];
        }
        $query = AtlasAemorMemoryCandidate::query();
        if ($scopeType !== null) {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }
        $candidates = $query->latest()->limit(100)->get();
        $seen = [];
        $conflicts = [];
        foreach ($candidates as $candidate) {
            $key = MissionCanonicalHash::sha256([
                'scope' => $candidate->scope_type.':'.$candidate->scope_id,
                'claim' => mb_strtolower(trim($candidate->claim)),
            ]);
            if (isset($seen[$key])) {
                $conflicts[] = [
                    'status' => 'conflicted',
                    'candidate_id' => $candidate->id,
                    'conflicts_with' => $seen[$key],
                    'resolution' => 'requires_freshness_and_evidence_review',
                ];
            }
            $seen[$key] = $candidate->id;
        }

        return [
            'schema_version' => 'atlas.aemor.memory_conflict_resolution.v1',
            'status' => $conflicts === [] ? 'clear' : 'watch',
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function causalityRank(AtlasAemorOutcome $outcome): array
    {
        $candidates = [];
        if ((array) ($outcome->evidence_refs ?? []) === []) {
            $candidates[] = ['cause' => 'missing_evidence', 'weight' => 0.95];
        }
        if ($outcome->status !== 'succeeded') {
            $candidates[] = ['cause' => 'execution_failed_or_blocked', 'weight' => 0.7];
        }
        if (data_get($outcome->context_utility, 'missing_sources', []) !== []) {
            $candidates[] = ['cause' => 'context_missing_required_sources', 'weight' => 0.65];
        }
        if (data_get($outcome->metrics, 'tests_passed') === false) {
            $candidates[] = ['cause' => 'tests_failed', 'weight' => 0.85];
        }
        if ($candidates === []) {
            $candidates[] = ['cause' => 'execution_strategy_likely_succeeded', 'weight' => 0.55];
        }
        usort($candidates, static fn (array $a, array $b): int => ($b['weight'] <=> $a['weight']));

        return [
            'schema_version' => 'atlas.aemor.outcome_causality_rank.v1',
            'primary_cause' => $candidates[0]['cause'],
            'candidates' => $candidates,
            'alternative_explanations' => array_slice($candidates, 1),
        ];
    }

    /**
     * @param  array<string,mixed>  $causality
     * @return array<string,mixed>
     */
    private function falseLearningGate(AtlasAemorOutcome $outcome, array $causality): array
    {
        $blockers = [];
        if ((array) ($outcome->evidence_refs ?? []) === []) {
            $blockers[] = 'missing_evidence_refs';
        }
        if ($outcome->status === 'succeeded' && data_get($outcome->metrics, 'tests_passed') !== true) {
            $blockers[] = 'success_without_test_or_gate_evidence';
        }
        if (count((array) ($causality['alternative_explanations'] ?? [])) > 0 && data_get($outcome->metrics, 'attribution_reviewed') !== true) {
            $blockers[] = 'alternative_explanations_not_reviewed';
        }

        return [
            'schema_version' => 'atlas.aemor.false_learning_gate.v1',
            'status' => $blockers === [] ? 'pass' : 'blocked_for_learning',
            'learning_allowed' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $causality
     * @return array<string,mixed>
     */
    private function outcomeAttribution(AtlasAemorOutcome $outcome, array $causality): array
    {
        $primary = (string) ($causality['primary_cause'] ?? 'unknown');
        $evidenceRefs = array_values((array) ($outcome->evidence_refs ?? []));
        $confidence = match ($primary) {
            'missing_evidence', 'tests_failed' => 0.9,
            'execution_failed_or_blocked', 'context_missing_required_sources' => 0.78,
            'execution_strategy_likely_succeeded' => 0.62,
            default => 0.45,
        };

        return [
            'schema_version' => 'atlas.aemor.outcome_attribution.v1',
            'status' => $evidenceRefs === [] ? 'blocked' : 'attributed',
            'primary_attribution' => $primary,
            'confidence' => $confidence,
            'supporting_causes' => array_slice((array) ($causality['candidates'] ?? []), 0, 3),
            'alternative_explanations' => array_values((array) ($causality['alternative_explanations'] ?? [])),
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repeatedFailureSuppression(AtlasAemorOutcome $outcome): array
    {
        if (! $outcome->failure_signature) {
            return [
                'schema_version' => 'atlas.aemor.repeated_failure_suppression.v1',
                'status' => 'clear',
                'repeat_count' => 0,
                'required_mitigation' => [],
            ];
        }
        $count = DatabaseTableAvailability::has('atlas_aemor_outcomes')
            ? AtlasAemorOutcome::query()->where('failure_signature', $outcome->failure_signature)->count()
            : 0;

        return [
            'schema_version' => 'atlas.aemor.repeated_failure_suppression.v1',
            'status' => $count >= 3 ? 'blocked' : ($count >= 2 ? 'watch' : 'clear'),
            'repeat_count' => $count,
            'failure_signature' => $outcome->failure_signature,
            'required_mitigation' => $count >= 2 ? ['include_negative_knowledge_in_apcr', 'run_targeted_repair_test'] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextRoi(AtlasAemorOutcome $outcome): array
    {
        $utility = (array) ($outcome->context_utility ?? []);
        $helpful = count((array) data_get($utility, 'helpful_sources', []));
        $irrelevant = count((array) data_get($utility, 'irrelevant_sources', []));
        $stale = count((array) data_get($utility, 'stale_sources', []));
        $missing = count((array) data_get($utility, 'missing_sources', []));
        $score = max(0, min(100, 75 + ($helpful * 5) - ($irrelevant * 4) - ($stale * 8) - ($missing * 12)));

        return [
            'schema_version' => 'atlas.aemor.context_roi_score.v1',
            'score' => $score,
            'helpful_sources_count' => $helpful,
            'irrelevant_sources_count' => $irrelevant,
            'stale_sources_count' => $stale,
            'missing_sources_count' => $missing,
            'status' => $score >= 70 ? 'good' : ($score >= 45 ? 'watch' : 'poor'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function patchQualityFingerprint(AtlasAemorOutcome $outcome): array
    {
        $patch = (array) ($outcome->patch_outcome ?? []);

        return [
            'schema_version' => 'atlas.aemor.patch_quality_fingerprint.v1',
            'files_changed' => (int) data_get($patch, 'files_changed', 0),
            'tests_run' => (int) data_get($patch, 'tests_run', 0),
            'repair_loops' => (int) data_get($patch, 'repair_loops', 0),
            'rollback_available' => (bool) data_get($patch, 'rollback_available', false),
            'risk' => ((int) data_get($patch, 'repair_loops', 0)) > 1 ? 'high' : 'normal',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function humanCorrection(AtlasAemorOutcome $outcome): array
    {
        $correction = data_get($outcome->metrics, 'human_correction');

        return [
            'schema_version' => 'atlas.aemor.human_correction_compression.v1',
            'status' => is_string($correction) && trim($correction) !== '' ? 'candidate' : 'none',
            'compressed_rule' => is_string($correction) ? trim($correction) : null,
            'scope_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $repeatedFailure
     * @param  array<string,mixed>  $falseLearning
     * @param  array<string,mixed>  $causality
     * @return array<string,mixed>
     */
    private function negativeKnowledge(AtlasAemorOutcome $outcome, array $repeatedFailure, array $falseLearning, array $causality): array
    {
        $entries = [];
        if ($outcome->status !== 'succeeded') {
            $entries[] = [
                'type' => 'failed_execution_pattern',
                'do_not_repeat' => $outcome->failure_signature ?: (string) ($causality['primary_cause'] ?? 'unattributed_failure'),
                'required_before_retry' => ['fresh_context_pass', 'targeted_test_or_gate'],
            ];
        }
        if (($falseLearning['status'] ?? null) !== 'pass') {
            $entries[] = [
                'type' => 'false_learning_blocker',
                'do_not_repeat' => 'promote_learning_without_evidence_tests_and_attribution_review',
                'required_before_retry' => array_values((array) ($falseLearning['blockers'] ?? [])),
            ];
        }
        if (($repeatedFailure['status'] ?? null) !== 'clear') {
            $entries[] = [
                'type' => 'repeated_failure_pattern',
                'do_not_repeat' => (string) ($repeatedFailure['failure_signature'] ?? 'same_failure_signature'),
                'required_before_retry' => array_values((array) ($repeatedFailure['required_mitigation'] ?? [])),
            ];
        }

        return [
            'schema_version' => 'atlas.aemor.negative_knowledge.v1',
            'status' => $entries === [] ? 'clear' : 'candidate',
            'entries' => $entries,
            'evidence_refs' => array_values((array) ($outcome->evidence_refs ?? [])),
            'promotion_policy' => 'requires_human_or_compounding_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerSkillReliability(AtlasAemorOutcome $outcome): array
    {
        $episode = $outcome->episode;
        $testsPassed = data_get($outcome->metrics, 'tests_passed');
        $score = 50;
        $score += $outcome->status === 'succeeded' ? 25 : -25;
        $score += $testsPassed === true ? 15 : ($testsPassed === false ? -15 : 0);
        $score += count((array) ($outcome->evidence_refs ?? [])) > 0 ? 10 : -10;
        $score = max(0, min(100, $score));

        return [
            'schema_version' => 'atlas.aemor.provider_skill_reliability.v1',
            'status' => 'observed',
            'provider' => $episode?->provider ?: 'unknown',
            'domain' => $episode?->domain ?: 'unknown',
            'flow_id' => $episode?->flow_id ?: 'unknown',
            'reliability_score' => $score,
            'signal' => $score >= 75 ? 'positive' : ($score >= 45 ? 'neutral' : 'negative'),
            'sample_policy' => 'single_episode_signal_not_global_ranking',
            'evidence_refs' => array_values((array) ($outcome->evidence_refs ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $causality
     * @param  array<string,mixed>  $contextRoi
     * @param  array<string,mixed>  $repeatedFailure
     * @return array<string,mixed>
     */
    private function counterfactualReplay(AtlasAemorOutcome $outcome, array $causality, array $contextRoi, array $repeatedFailure): array
    {
        $hypotheses = [];
        if ((int) ($contextRoi['missing_sources_count'] ?? 0) > 0) {
            $hypotheses[] = [
                'if' => 'mandatory_retrieval_included_missing_sources',
                'then' => 'execution_plan_would_have_lower_context_risk',
                'confidence' => 0.72,
            ];
        }
        if (data_get($outcome->metrics, 'tests_passed') !== true) {
            $hypotheses[] = [
                'if' => 'targeted_tests_were_required_before_completion',
                'then' => 'false_success_or_repeat_failure_would_be_blocked',
                'confidence' => 0.8,
            ];
        }
        if (($repeatedFailure['status'] ?? null) !== 'clear') {
            $hypotheses[] = [
                'if' => 'negative_knowledge_was_injected_before_execution',
                'then' => 'same_failure_strategy_would_be_suppressed',
                'confidence' => 0.68,
            ];
        }
        if ($hypotheses === []) {
            $hypotheses[] = [
                'if' => 'same_strategy_replayed_with_current_evidence',
                'then' => 'expected_outcome_remains_stable',
                'confidence' => 0.55,
            ];
        }

        return [
            'schema_version' => 'atlas.aemor.counterfactual_replay.v1',
            'status' => 'simulated',
            'primary_cause' => $causality['primary_cause'] ?? 'unknown',
            'hypotheses' => $hypotheses,
            'executes_provider' => false,
            'executes_tools' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryBudget(?string $scopeType, ?string $scopeId): array
    {
        if (! DatabaseTableAvailability::has('atlas_aemor_memory_candidates')) {
            $candidateCount = 0;
        } else {
            $query = AtlasAemorMemoryCandidate::query();
            if ($scopeType !== null) {
                $query->where('scope_type', $scopeType);
            }
            if ($scopeId !== null) {
                $query->where('scope_id', $scopeId);
            }
            $candidateCount = $query->count();
        }
        $budget = 50;
        $status = $candidateCount > $budget ? 'over_budget' : ($candidateCount > 40 ? 'watch' : 'within_budget');

        return [
            'schema_version' => 'atlas.aemor.memory_budget.v1',
            'status' => $status,
            'candidate_count' => $candidateCount,
            'budget' => $budget,
            'recommended_action' => match ($status) {
                'over_budget' => 'compress_or_reject_low_confidence_candidates',
                'watch' => 'review_candidates_before_more_promotion',
                default => 'keep_collecting_evidence_backed_candidates',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $negativeKnowledge
     * @param  array<string,mixed>  $humanCorrection
     * @param  array<string,mixed>  $repeatedFailure
     * @return array<string,mixed>
     */
    private function operationalDoctrine(AtlasAemorOutcome $outcome, array $negativeKnowledge, array $humanCorrection, array $repeatedFailure): array
    {
        $rules = [];
        if (($negativeKnowledge['status'] ?? null) === 'candidate') {
            $rules[] = [
                'rule' => 'Inject matching negative knowledge before similar future execution.',
                'source' => 'negative_knowledge',
            ];
        }
        if (($humanCorrection['status'] ?? null) === 'candidate') {
            $rules[] = [
                'rule' => $humanCorrection['compressed_rule'] ?? 'Apply human correction before next similar execution.',
                'source' => 'human_correction',
            ];
        }
        if (($repeatedFailure['status'] ?? null) !== 'clear') {
            $rules[] = [
                'rule' => 'Require targeted mitigation before retrying repeated failure signature.',
                'source' => 'repeated_failure_suppression',
            ];
        }
        if ($outcome->status === 'succeeded' && data_get($outcome->metrics, 'tests_passed') === true) {
            $rules[] = [
                'rule' => 'Preserve tested execution strategy as candidate doctrine for matching scope.',
                'source' => 'successful_tested_outcome',
            ];
        }

        return [
            'schema_version' => 'atlas.aemor.operational_doctrine.v1',
            'status' => $rules === [] ? 'none' : 'candidate',
            'rules' => $rules,
            'promotion_policy' => 'proposal_only_no_auto_policy_mutation',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityScore(AtlasAemorOutcome $outcome, array $falseLearning, array $repeatedFailure, array $contextRoi): array
    {
        $score = 50;
        $score += count((array) ($outcome->evidence_refs ?? [])) > 0 ? 15 : -30;
        $score += $falseLearning['status'] === 'pass' ? 20 : -20;
        $score += $repeatedFailure['status'] === 'clear' ? 10 : -15;
        $score += ((int) ($contextRoi['score'] ?? 0)) >= 70 ? 10 : -10;
        $score = max(0, min(100, $score));

        return [
            'schema_version' => 'atlas.aemor.quality_score.v1',
            'score' => $score,
            'status' => $score >= 80 ? 'strong' : ($score >= 55 ? 'watch' : 'weak'),
            'breakdown' => [
                'evidence_coverage' => count((array) ($outcome->evidence_refs ?? [])),
                'false_learning_gate' => $falseLearning['status'] ?? 'unknown',
                'repeated_failure' => $repeatedFailure['status'] ?? 'unknown',
                'context_roi' => $contextRoi['score'] ?? null,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function policyProposals(AtlasAemorOutcome $outcome, array $repeatedFailure, array $falseLearning): array
    {
        $proposals = [];
        if (($repeatedFailure['status'] ?? null) === 'blocked') {
            $proposals[] = [
                'schema_version' => 'atlas.aemor.policy_proposal.v1',
                'proposal_type' => 'repeated_failure_gate',
                'status' => 'requires_review',
                'reason' => 'Same failure signature repeated three or more times.',
            ];
        }
        if (($falseLearning['status'] ?? null) !== 'pass') {
            $proposals[] = [
                'schema_version' => 'atlas.aemor.policy_proposal.v1',
                'proposal_type' => 'anti_false_learning_gate',
                'status' => 'requires_review',
                'reason' => 'Learning was blocked; future similar outcomes need stronger evidence.',
            ];
        }

        return $proposals;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $id, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'blockers' => [[
                'id' => $id,
                'reason' => $reason,
            ]],
            'claim_policy' => $this->runtime->claimPolicy(),
        ];
    }
}
