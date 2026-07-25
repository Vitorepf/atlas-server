<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Programming\Support\RivalsOneShotDimensionCreditSupport;

/**
 * Rivals One-Shot Enterprise Evaluation Service v1.
 *
 * Diagnostic evaluator that scores an Atlas Code one-shot delivery against
 * the canonical rubric. Never executes providers, never spends tokens,
 * never promotes the Rivals claim — only `external_rivals_certification`
 * can do that, and that path is governed elsewhere.
 *
 * Inputs (all optional):
 *   - replay_manifest (planned or executed)
 *   - case_manifest (canonical)
 *   - evidence_pack (optional, structured)
 *   - review_packet (optional)
 *   - completion_claim (optional)
 *   - observed_time_metrics (optional)
 *
 * Output schema: atlas.programming.rivals_one_shot_enterprise_evaluation.v1
 */
class AtlasRivalsOneShotEnterpriseEvaluationService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_one_shot_enterprise_evaluation.v1';

    public const MAX_SCORE = 100;

    public const GRADE_ENTERPRISE_READY = 'enterprise_ready';

    public const GRADE_REVIEW_REQUIRED = 'review_required';

    public const GRADE_NOT_ENTERPRISE_READY = 'not_enterprise_ready';

    public const GRADE_INVALID = 'invalid';

    public function __construct(
        private readonly AtlasRivalsOneShotEnterpriseRubricService $rubric,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $replayManifest = is_array($input['replay_manifest'] ?? null) ? $input['replay_manifest'] : null;
        $caseManifest = is_array($input['case_manifest'] ?? null) ? $input['case_manifest'] : null;
        $evidencePack = is_array($input['evidence_pack'] ?? null) ? $input['evidence_pack'] : [];
        $reviewPacket = is_array($input['review_packet'] ?? null) ? $input['review_packet'] : null;
        $completionClaim = is_array($input['completion_claim'] ?? null) ? $input['completion_claim'] : null;
        $observedTime = is_array($input['observed_time_metrics'] ?? null) ? $input['observed_time_metrics'] : [];
        $evaluationMode = (string) ($input['evaluation_mode'] ?? 'local_manifest_evaluation');

        $rubric = $this->rubric->rubric();
        $hardFails = $this->collectHardFails($replayManifest, $caseManifest, $evidencePack, $completionClaim);
        $missingEvidence = $this->collectMissingEvidence($replayManifest, $caseManifest, $evidencePack);

        $dimensionScores = [];
        $totalEarned = 0.0;
        foreach ($rubric['scoring_dimensions'] as $dimension) {
            $scored = RivalsOneShotDimensionCreditSupport::scoreDimension(
                $dimension,
                $replayManifest,
                $caseManifest,
                $evidencePack,
                $reviewPacket,
                $observedTime,
                $hardFails,
            );
            $dimensionScores[] = $scored;
            $totalEarned += (float) $scored['earned'];
        }

        $totalScore = (int) round($totalEarned);
        $grade = RivalsOneShotDimensionCreditSupport::resolveGrade($totalScore, $hardFails);
        $status = $grade === self::GRADE_INVALID
            ? 'invalid'
            : ($evaluationMode === 'local_manifest_evaluation' ? 'evaluated_local_manifest' : 'evaluated');
        $diagnosticScore = $grade === self::GRADE_INVALID ? null : $totalScore;
        $claimReady = false; // Local fixture evaluation never gates the claim.

        $humanInterventionEstimate = RivalsOneShotDimensionCreditSupport::humanInterventionEstimate($evidencePack, $reviewPacket);
        $reviewCostEstimate = RivalsOneShotDimensionCreditSupport::reviewCostEstimate($evidencePack, $reviewPacket);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'rubric_id' => AtlasRivalsOneShotEnterpriseRubricService::RUBRIC_ID,
            'rubric_schema_version' => AtlasRivalsOneShotEnterpriseRubricService::SCHEMA_VERSION,
            'generated_at' => now()->toJSON(),
            'status' => $status,
            'evaluation_mode' => $evaluationMode,
            'primary_objective' => AtlasRivalsOneShotEnterpriseRubricService::PRIMARY_OBJECTIVE,
            'speed_is_secondary' => true,
            'time_cannot_compensate_quality' => true,
            'quality_can_compensate_time' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'synthetic_scores_allowed' => false,
            'claim_ready' => $claimReady,
            'diagnostic_score' => $diagnosticScore,
            'claim_score' => null,
            'total_score' => $totalScore,
            'max_score' => self::MAX_SCORE,
            'grade' => $grade,
            'dimension_scores' => $dimensionScores,
            'hard_fails' => $hardFails,
            'missing_evidence' => $missingEvidence,
            'improvement_priorities' => RivalsOneShotDimensionCreditSupport::improvementPriorities($dimensionScores, $hardFails),
            'human_intervention_estimate' => $humanInterventionEstimate,
            'review_cost_estimate' => $reviewCostEstimate,
            'time_cost_observed_but_secondary' => [
                'observed_wall_clock_seconds' => $observedTime['wall_clock_seconds'] ?? null,
                'observed_token_count_estimate' => $observedTime['token_count_estimate'] ?? null,
                'is_secondary_metric' => true,
                'can_break_ties_only' => true,
            ],
            'verdict' => RivalsOneShotDimensionCreditSupport::verdict($grade, $totalScore, $hardFails),
            'limitations' => $this->limitations($evaluationMode),
            'inputs' => [
                'replay_manifest_provided' => $replayManifest !== null,
                'case_manifest_provided' => $caseManifest !== null,
                'evidence_pack_provided' => $evidencePack !== [],
                'review_packet_provided' => $reviewPacket !== null,
                'completion_claim_provided' => $completionClaim !== null,
            ],
            'safety' => [
                'evaluation_dispatches_provider' => false,
                'evaluation_spends_tokens' => false,
                'evaluation_is_diagnostic' => true,
                'evaluation_promotes_claim' => false,
            ],
            'note' => 'Evaluation e diagnostica. Score reflete o que o manifest e a evidencia local provam; sem bateria provider real, nao e claim. external_rivals_certification continua governando o claim final.',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $completionClaim
     * @return list<string>
     */
    private function collectHardFails(?array $replayManifest, ?array $caseManifest, array $evidencePack, ?array $completionClaim): array
    {
        $fails = [];

        if ($replayManifest === null || ! ($replayManifest['valid'] ?? false)) {
            $fails[] = 'missing_replay_manifest';
        }

        if (($replayManifest['atlas_arm']['runtime'] ?? null) !== 'forge') {
            $fails[] = 'atlas_arm_not_forge';
        }

        $acceptanceGates = $replayManifest['acceptance_gates'] ?? null;
        if (! is_array($acceptanceGates) || $acceptanceGates === []) {
            $fails[] = 'missing_acceptance_gates';
        }

        $objective = $caseManifest['case']['objective'] ?? ($caseManifest['objective'] ?? null);
        if (! is_string($objective) || trim($objective) === '') {
            $fails[] = 'missing_business_rule';
        }

        $docsConsulted = (array) ($evidencePack['canonical_docs_consulted'] ?? []);
        if (($evidencePack['canonical_docs_required'] ?? false) === true && $docsConsulted === []) {
            $fails[] = 'missing_canonical_docs';
        }

        if (($evidencePack['tests_present'] ?? null) === false) {
            $fails[] = 'missing_tests_or_test_evidence';
        }

        if (($completionClaim['human_approved'] ?? null) === false && ($completionClaim['auto_completed'] ?? false) === true) {
            $fails[] = 'auto_completion_without_review';
        }

        if (($evidencePack['fake_evidence_flag'] ?? false) === true) {
            $fails[] = 'fake_evidence';
        }

        if (($evidencePack['provider_call_without_approval'] ?? false) === true) {
            $fails[] = 'provider_call_without_approval';
        }

        if (($evidencePack['workspace_state_for_claim'] ?? null) === 'dirty') {
            $fails[] = 'dirty_workspace_for_claim';
        }

        if (($evidencePack['workspace_after_clean_check_ran'] ?? false) === true
            && ($evidencePack['workspace_after_clean_check_clean'] ?? null) === false
        ) {
            $fails[] = 'dirty_workspace_after_run';
        }

        if (($evidencePack['tracked_python_bytecode_in_workspace'] ?? false) === true) {
            $fails[] = 'tracked_python_bytecode_in_workspace';
        }

        if (($evidencePack['synthetic_score_admitted_as_real_claim'] ?? false) === true) {
            $fails[] = 'synthetic_score_used_as_real_claim';
        }

        $allowed = AtlasRivalsOneShotEnterpriseRubricService::GLOBAL_HARD_FAIL_CONDITIONS;

        return array_values(array_unique(array_filter($fails, static fn (string $f): bool => in_array($f, $allowed, true))));
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     * @return list<string>
     */
    private function collectMissingEvidence(?array $replayManifest, ?array $caseManifest, array $evidencePack): array
    {
        $missing = [];
        if ($replayManifest === null) {
            $missing[] = 'replay_manifest';
        }
        if ($caseManifest === null) {
            $missing[] = 'case_manifest';
        }
        if (! isset($evidencePack['test_run_log'])) {
            $missing[] = 'test_run_log';
        }
        if (! isset($evidencePack['patch_diff'])) {
            $missing[] = 'patch_diff';
        }
        if (! isset($evidencePack['canonical_docs_consulted'])) {
            $missing[] = 'canonical_docs_consulted';
        }
        if (! isset($evidencePack['quality_scan_log'])) {
            $missing[] = 'quality_scan_log';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function limitations(string $evaluationMode): array
    {
        if ($evaluationMode === 'local_manifest_evaluation') {
            return [
                'no_real_provider_baseline',
                'no_real_patch_diff_comparison',
                'no_real_human_intervention_log',
                'no_real_business_outcome_review',
                'score_is_diagnostic_not_claim',
            ];
        }

        return ['evaluation_mode_unknown'];
    }
}
