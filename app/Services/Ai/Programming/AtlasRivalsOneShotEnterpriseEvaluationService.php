<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

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
            $scored = $this->scoreDimension($dimension, $replayManifest, $caseManifest, $evidencePack, $reviewPacket, $observedTime, $hardFails);
            $dimensionScores[] = $scored;
            $totalEarned += (float) $scored['earned'];
        }

        $totalScore = (int) round($totalEarned);
        $grade = $this->resolveGrade($totalScore, $hardFails);
        $status = $grade === self::GRADE_INVALID
            ? 'invalid'
            : ($evaluationMode === 'local_manifest_evaluation' ? 'evaluated_local_manifest' : 'evaluated');
        $diagnosticScore = $grade === self::GRADE_INVALID ? null : $totalScore;
        $claimReady = false; // Local fixture evaluation never gates the claim.

        $humanInterventionEstimate = $this->humanInterventionEstimate($evidencePack, $reviewPacket);
        $reviewCostEstimate = $this->reviewCostEstimate($evidencePack, $reviewPacket);

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
            'improvement_priorities' => $this->improvementPriorities($dimensionScores, $hardFails),
            'human_intervention_estimate' => $humanInterventionEstimate,
            'review_cost_estimate' => $reviewCostEstimate,
            'time_cost_observed_but_secondary' => [
                'observed_wall_clock_seconds' => $observedTime['wall_clock_seconds'] ?? null,
                'observed_token_count_estimate' => $observedTime['token_count_estimate'] ?? null,
                'is_secondary_metric' => true,
                'can_break_ties_only' => true,
            ],
            'verdict' => $this->verdict($grade, $totalScore, $hardFails),
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
     * @param  array<string,mixed>  $dimension
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     * @param  array<string,mixed>  $observedTime
     * @param  list<string>  $hardFails
     * @return array<string,mixed>
     */
    private function scoreDimension(
        array $dimension,
        ?array $replayManifest,
        ?array $caseManifest,
        array $evidencePack,
        ?array $reviewPacket,
        array $observedTime,
        array $hardFails,
    ): array {
        $id = (string) $dimension['id'];
        $weight = (float) $dimension['weight'];

        $localHardFails = array_values(array_intersect($dimension['hard_fail_conditions'] ?? [], $hardFails));
        if ($localHardFails !== []) {
            return [
                'id' => $id,
                'weight' => $weight,
                'credit_fraction' => 0.0,
                'earned' => 0.0,
                'status' => 'hard_failed',
                'rationale' => 'Hard fail conditions hit: '.implode(',', $localHardFails),
                'triggered_hard_fails' => $localHardFails,
            ];
        }

        $credit = $this->dimensionCredit($id, $replayManifest, $caseManifest, $evidencePack, $reviewPacket, $observedTime);
        $credit = max(0.0, min(1.0, $credit['credit']));

        return [
            'id' => $id,
            'weight' => $weight,
            'credit_fraction' => round($credit, 2),
            'earned' => round($weight * $credit, 2),
            'status' => $credit >= 1.0 ? 'passed' : ($credit >= 0.5 ? 'partial' : 'weak'),
            'rationale' => 'Score derived from manifest + evidence pack signals; missing evidence reduces credit fractionally.',
            'triggered_hard_fails' => [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     * @param  array<string,mixed>  $observedTime
     * @return array{credit:float}
     */
    private function dimensionCredit(
        string $id,
        ?array $replayManifest,
        ?array $caseManifest,
        array $evidencePack,
        ?array $reviewPacket,
        array $observedTime,
    ): array {
        return ['credit' => match ($id) {
            'business_rule_alignment' => $this->creditBusinessRule($caseManifest, $evidencePack),
            'canonical_documentation_adherence' => $this->creditCanonicalDocs($evidencePack),
            'one_shot_completeness' => $this->creditOneShot($replayManifest, $evidencePack),
            'functional_correctness' => $this->creditFunctional($evidencePack),
            'real_tests_and_risk_coverage' => $this->creditTests($evidencePack),
            'enterprise_architecture' => $this->creditArchitecture($evidencePack, $reviewPacket),
            'forge_governance' => $this->creditForge($replayManifest, $reviewPacket),
            'operational_safety' => $this->creditOperationalSafety($evidencePack),
            'implementation_quality' => $this->creditImplementationQuality($evidencePack),
            'operator_experience' => $this->creditOperatorExperience($evidencePack),
            'observability_and_evidence' => $this->creditObservability($replayManifest, $evidencePack),
            'autonomy_and_intervention_load' => $this->creditAutonomy($evidencePack, $reviewPacket),
            'time_and_cost_efficiency' => $this->creditTimeAndCost($observedTime),
            default => 0.0,
        }];
    }

    /**
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditBusinessRule(?array $caseManifest, array $evidencePack): float
    {
        $objective = $caseManifest['case']['objective'] ?? ($caseManifest['objective'] ?? null);
        if (! is_string($objective) || trim($objective) === '') {
            return 0.0;
        }
        if (($evidencePack['business_rule_check'] ?? null) === 'passed') {
            return 1.0;
        }
        if (($evidencePack['business_rule_check'] ?? null) === 'partial') {
            return 0.6;
        }

        return 0.7; // Manifest exists but no explicit review; partial-default credit.
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditCanonicalDocs(array $evidencePack): float
    {
        $consulted = (array) ($evidencePack['canonical_docs_consulted'] ?? []);
        if ($consulted !== []) {
            return 1.0;
        }
        if (($evidencePack['canonical_docs_required'] ?? false) === false) {
            return 0.6;
        }

        return 0.4;
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditOneShot(?array $replayManifest, array $evidencePack): float
    {
        $gates = (array) ($replayManifest['acceptance_gates'] ?? []);
        if ($gates === []) {
            return 0.0;
        }
        $todoCount = (int) ($evidencePack['todo_count'] ?? 0);
        if ($todoCount > 0) {
            return max(0.0, 1.0 - 0.2 * $todoCount);
        }

        return 0.85;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditFunctional(array $evidencePack): float
    {
        $exitCodes = (array) ($evidencePack['command_exit_codes'] ?? []);
        $testLog = $evidencePack['test_run_log'] ?? null;
        if (! is_string($testLog) || trim($testLog) === '') {
            if ($exitCodes === []) {
                return 0.4;
            }
        }
        if (($evidencePack['tests_passed'] ?? null) === true && ! in_array(false, array_map(static fn ($v): bool => (bool) $v, $exitCodes), true)) {
            return 1.0;
        }
        if (($evidencePack['tests_passed'] ?? null) === true) {
            return 0.8;
        }

        return 0.5;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditTests(array $evidencePack): float
    {
        $assertions = (int) ($evidencePack['assertion_count'] ?? 0);
        $files = (array) ($evidencePack['test_files_changed'] ?? []);
        if ($files === [] && $assertions === 0) {
            return 0.0;
        }
        if ($assertions >= 50 && $files !== []) {
            return 1.0;
        }
        if ($files !== []) {
            return 0.7;
        }

        return 0.5;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     */
    private function creditArchitecture(array $evidencePack, ?array $reviewPacket): float
    {
        $reviewOk = is_array($reviewPacket) && ($reviewPacket['architecture_review'] ?? null) === 'passed';
        $layered = (array) ($evidencePack['file_count_by_layer'] ?? []);
        if ($reviewOk && $layered !== []) {
            return 1.0;
        }
        if ($layered !== []) {
            return 0.8;
        }

        return 0.6;
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>|null  $reviewPacket
     */
    private function creditForge(?array $replayManifest, ?array $reviewPacket): float
    {
        if (($replayManifest['atlas_arm']['runtime'] ?? null) !== 'forge') {
            return 0.0;
        }
        if (is_array($reviewPacket) && ($reviewPacket['review_status'] ?? null) === 'approved') {
            return 1.0;
        }

        return 0.8;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditOperationalSafety(array $evidencePack): float
    {
        if (($evidencePack['provider_call_without_approval'] ?? false) === true) {
            return 0.0;
        }
        if (($evidencePack['workspace_state_for_claim'] ?? null) === 'dirty') {
            return 0.0;
        }
        if (($evidencePack['preflight_status'] ?? null) === 'ready_for_dry_run' || ($evidencePack['preflight_status'] ?? null) === 'ready_for_provider_battery') {
            return 1.0;
        }

        return 0.7;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditImplementationQuality(array $evidencePack): float
    {
        $quality = $evidencePack['quality_scan_log'] ?? null;
        if ($quality === 'passed') {
            return 1.0;
        }
        if ($quality === 'warnings') {
            return 0.7;
        }
        if ($quality === 'failed') {
            return 0.0;
        }

        return 0.6;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditOperatorExperience(array $evidencePack): float
    {
        $signature = (string) ($evidencePack['command_signature'] ?? '');
        $hasJson = str_contains($signature, '--json');
        $hasStrict = str_contains($signature, '--strict');
        if ($signature === '') {
            return 0.5;
        }
        if ($hasJson && $hasStrict) {
            return 1.0;
        }
        if ($hasJson) {
            return 0.7;
        }

        return 0.4;
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @param  array<string,mixed>  $evidencePack
     */
    private function creditObservability(?array $replayManifest, array $evidencePack): float
    {
        if ($replayManifest === null || ! ($replayManifest['valid'] ?? false)) {
            return 0.0;
        }
        $paths = (array) ($evidencePack['evidence_paths'] ?? []);
        if ($paths !== []) {
            return 1.0;
        }

        return 0.7;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     */
    private function creditAutonomy(array $evidencePack, ?array $reviewPacket): float
    {
        $interventions = (int) ($evidencePack['human_intervention_log_count'] ?? 0);
        $interventions = max($interventions, (int) (is_array($reviewPacket) ? ($reviewPacket['intervention_count'] ?? 0) : 0));
        if ($interventions === 0) {
            return 1.0;
        }
        if ($interventions <= 2) {
            return 0.7;
        }
        if ($interventions <= 5) {
            return 0.4;
        }

        return 0.0;
    }

    /**
     * @param  array<string,mixed>  $observedTime
     */
    private function creditTimeAndCost(array $observedTime): float
    {
        $wallClock = (int) ($observedTime['wall_clock_seconds'] ?? 0);
        if ($wallClock === 0) {
            return 0.7;
        }
        if ($wallClock <= 1800) {
            return 1.0;
        }
        if ($wallClock <= 3600) {
            return 0.8;
        }
        if ($wallClock <= 7200) {
            return 0.6;
        }

        return 0.4;
    }

    /**
     * @param  array<int,array<string,mixed>>  $dimensionScores
     * @param  list<string>  $hardFails
     * @return list<string>
     */
    private function improvementPriorities(array $dimensionScores, array $hardFails): array
    {
        if ($hardFails !== []) {
            return array_map(static fn (string $f): string => 'resolve_hard_fail:'.$f, $hardFails);
        }
        $sorted = collect($dimensionScores)
            ->sortBy(static fn (array $d): float => (float) $d['credit_fraction'])
            ->take(3)
            ->map(static fn (array $d): string => 'improve:'.$d['id'])
            ->values()
            ->all();

        return $sorted;
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     * @return array<string,mixed>
     */
    private function humanInterventionEstimate(array $evidencePack, ?array $reviewPacket): array
    {
        $count = (int) ($evidencePack['human_intervention_log_count'] ?? 0);
        $count = max($count, (int) (is_array($reviewPacket) ? ($reviewPacket['intervention_count'] ?? 0) : 0));

        return [
            'count' => $count,
            'load_level' => match (true) {
                $count === 0 => 'minimal_review_only',
                $count <= 2 => 'low',
                $count <= 5 => 'moderate',
                default => 'high',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,mixed>|null  $reviewPacket
     * @return array<string,mixed>
     */
    private function reviewCostEstimate(array $evidencePack, ?array $reviewPacket): array
    {
        $minutes = (int) ($evidencePack['review_minutes_estimate'] ?? 0);
        if ($minutes === 0 && is_array($reviewPacket)) {
            $complexity = (string) ($reviewPacket['review_complexity'] ?? '');
            $minutes = match ($complexity) {
                'low' => 15,
                'medium' => 45,
                'high' => 120,
                default => 0,
            };
        }

        return [
            'estimated_minutes' => $minutes,
            'band' => match (true) {
                $minutes === 0 => 'unknown',
                $minutes <= 20 => 'short',
                $minutes <= 60 => 'medium',
                $minutes <= 120 => 'long',
                default => 'very_long',
            },
        ];
    }

    /**
     * @param  list<string>  $hardFails
     */
    private function resolveGrade(int $totalScore, array $hardFails): string
    {
        if ($hardFails !== []) {
            return self::GRADE_INVALID;
        }
        if ($totalScore >= 90) {
            return self::GRADE_ENTERPRISE_READY;
        }
        if ($totalScore >= 75) {
            return self::GRADE_REVIEW_REQUIRED;
        }

        return self::GRADE_NOT_ENTERPRISE_READY;
    }

    /**
     * @param  list<string>  $hardFails
     * @return array<string,mixed>
     */
    private function verdict(string $grade, int $totalScore, array $hardFails): array
    {
        return [
            'grade' => $grade,
            'total_score' => $totalScore,
            'max_score' => self::MAX_SCORE,
            'hard_fails' => $hardFails,
            'summary' => match ($grade) {
                self::GRADE_ENTERPRISE_READY => 'Entrega cumpre os pre-requisitos one-shot enterprise no escopo local — claim externo continua condicionado a bateria provider real.',
                self::GRADE_REVIEW_REQUIRED => 'Entrega tem qualidade aceitavel mas exige revisao humana para fechar gaps antes de qualquer claim.',
                self::GRADE_NOT_ENTERPRISE_READY => 'Entrega ainda nao atende padrao one-shot enterprise; resolver dimensoes mais fracas.',
                self::GRADE_INVALID => 'Hard fail invalida qualquer claim Atlas. Score numerico e ignorado.',
                default => 'Grade desconhecida.',
            },
        ];
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
