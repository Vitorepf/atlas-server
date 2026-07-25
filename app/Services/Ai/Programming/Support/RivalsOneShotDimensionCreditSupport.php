<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;

/**
 * Pure dimension-credit / grade helpers for rivals one-shot enterprise evaluation.
 *
 * Extracted from AtlasRivalsOneShotEnterpriseEvaluationService private scoring methods.
 * No I/O, no provider calls, no time side effects.
 */
final class RivalsOneShotDimensionCreditSupport
{
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
    public static function scoreDimension(
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

        $credit = self::dimensionCredit($id, $replayManifest, $caseManifest, $evidencePack, $reviewPacket, $observedTime);
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
    public static function dimensionCredit(
        string $id,
        ?array $replayManifest,
        ?array $caseManifest,
        array $evidencePack,
        ?array $reviewPacket,
        array $observedTime,
    ): array {
        return ['credit' => match ($id) {
            'business_rule_alignment' => self::creditBusinessRule($caseManifest, $evidencePack),
            'canonical_documentation_adherence' => self::creditCanonicalDocs($evidencePack),
            'one_shot_completeness' => self::creditOneShot($replayManifest, $evidencePack),
            'functional_correctness' => self::creditFunctional($evidencePack),
            'real_tests_and_risk_coverage' => self::creditTests($evidencePack),
            'enterprise_architecture' => self::creditArchitecture($evidencePack, $reviewPacket),
            'forge_governance' => self::creditForge($replayManifest, $reviewPacket),
            'operational_safety' => self::creditOperationalSafety($evidencePack),
            'implementation_quality' => self::creditImplementationQuality($evidencePack),
            'operator_experience' => self::creditOperatorExperience($evidencePack),
            'observability_and_evidence' => self::creditObservability($replayManifest, $evidencePack),
            'autonomy_and_intervention_load' => self::creditAutonomy($evidencePack, $reviewPacket),
            'time_and_cost_efficiency' => self::creditTimeAndCost($observedTime),
            default => 0.0,
        }];
    }

    /**
     * @param  array<string,mixed>|null  $caseManifest
     * @param  array<string,mixed>  $evidencePack
     */
    public static function creditBusinessRule(?array $caseManifest, array $evidencePack): float
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
    public static function creditCanonicalDocs(array $evidencePack): float
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
    public static function creditOneShot(?array $replayManifest, array $evidencePack): float
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
    public static function creditFunctional(array $evidencePack): float
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
    public static function creditTests(array $evidencePack): float
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
    public static function creditArchitecture(array $evidencePack, ?array $reviewPacket): float
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
    public static function creditForge(?array $replayManifest, ?array $reviewPacket): float
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
    public static function creditOperationalSafety(array $evidencePack): float
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
    public static function creditImplementationQuality(array $evidencePack): float
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
    public static function creditOperatorExperience(array $evidencePack): float
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
    public static function creditObservability(?array $replayManifest, array $evidencePack): float
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
    public static function creditAutonomy(array $evidencePack, ?array $reviewPacket): float
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
    public static function creditTimeAndCost(array $observedTime): float
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
    public static function improvementPriorities(array $dimensionScores, array $hardFails): array
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
    public static function humanInterventionEstimate(array $evidencePack, ?array $reviewPacket): array
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
    public static function reviewCostEstimate(array $evidencePack, ?array $reviewPacket): array
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
    public static function resolveGrade(int $totalScore, array $hardFails): string
    {
        if ($hardFails !== []) {
            return AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID;
        }
        if ($totalScore >= 90) {
            return AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_ENTERPRISE_READY;
        }
        if ($totalScore >= 75) {
            return AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_REVIEW_REQUIRED;
        }

        return AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_NOT_ENTERPRISE_READY;
    }

    /**
     * @param  list<string>  $hardFails
     * @return array<string,mixed>
     */
    public static function verdict(string $grade, int $totalScore, array $hardFails): array
    {
        return [
            'grade' => $grade,
            'total_score' => $totalScore,
            'max_score' => AtlasRivalsOneShotEnterpriseEvaluationService::MAX_SCORE,
            'hard_fails' => $hardFails,
            'summary' => match ($grade) {
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_ENTERPRISE_READY => 'Entrega cumpre os pre-requisitos one-shot enterprise no escopo local — claim externo continua condicionado a bateria provider real.',
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_REVIEW_REQUIRED => 'Entrega tem qualidade aceitavel mas exige revisao humana para fechar gaps antes de qualquer claim.',
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_NOT_ENTERPRISE_READY => 'Entrega ainda nao atende padrao one-shot enterprise; resolver dimensoes mais fracas.',
                AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID => 'Hard fail invalida qualquer claim Atlas. Score numerico e ignorado.',
                default => 'Grade desconhecida.',
            },
        ];
    }
}
