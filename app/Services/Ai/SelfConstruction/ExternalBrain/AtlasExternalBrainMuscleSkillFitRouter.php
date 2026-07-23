<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure muscle-skill fit router. Matches a queued task (family, required skills, file scope,
 * risk level) against candidate muscles (workers or local subscription clients) and ranks them
 * by how likely each is to deliver green evidence without give_back churn.
 *
 * INPUT:
 *   task_family:     string
 *   required_skills: list<string>
 *   file_scope:      list<string>   — allowed_files for the task
 *   risk_level:      string         — low|medium|high
 *   candidates: list<{
 *     muscle_id:               string
 *     type?:                   string   — worker|local_subscription_client
 *     skills?:                 list<string>
 *     capabilities?:           list<string>  — extra capability tags (e.g. model_tier names)
 *     history?: array<string, {success?:int, give_back?:int, scope_failure?:int, total?:int}>
 *                                — keyed by task_family
 *     recent_give_back_rate?:  float  — overall recent give_back rate, 0..1
 *     max_risk_level?:         string — highest risk_level this muscle is trusted for
 *   }>
 *
 * DISQUALIFICATION (never ranked as primary, AC3):
 *   A candidate whose history for THIS task_family shows give_back_count >= DISQUALIFY_GIVE_BACK_COUNT
 *   OR scope_failure_count >= DISQUALIFY_SCOPE_FAILURE_COUNT is demoted below every non-disqualified
 *   candidate, regardless of fit_score. It still appears in ranked_muscles (never silently dropped) —
 *   transparency over the rejection beats hiding it.
 *
 * FIT SCORE (0..1, higher = better fit):
 *   skill_overlap        (0.40 weight) — fraction of required_skills the candidate has.
 *   family_success_rate  (0.35 weight) — candidate's historical success_rate for this task_family
 *                                        (defaults to 0.5 — neutral — when no history exists).
 *   recent_reliability    (0.25 weight) — 1 - recent_give_back_rate.
 *   A risk_level above the candidate's max_risk_level caps fit_score at RISK_MISMATCH_CAP.
 *
 * OUTPUT:
 *   { schema, ranked_muscles:list<{muscle_id, fit_score, risk_reasons, expected_success_confidence, rank}>,
 *     primary_muscle, fallback_muscle }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainMuscleSkillFitRouter
{
    public const SCHEMA = 'atlas.external_brain.muscle_skill_fit_router.v1';

    private const RISK_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    private const SKILL_OVERLAP_WEIGHT   = 0.40;
    private const FAMILY_SUCCESS_WEIGHT  = 0.35;
    private const RECENT_RELIABILITY_WEIGHT = 0.25;

    private const DISQUALIFY_GIVE_BACK_COUNT     = 2;
    private const DISQUALIFY_SCOPE_FAILURE_COUNT = 1;

    private const RISK_MISMATCH_CAP = 0.30;

    /** Small preference weight for a scope-tag match with above-neutral historical success. */
    private const SCOPE_MATCH_WEIGHT = 0.10;

    /** Minimum sample count required before scope-tag history can increase fit_score. */
    private const SCOPE_MIN_SAMPLE_COUNT = 3;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $taskFamily     = trim((string) ($input['task_family'] ?? ''));
        $requiredSkills = array_values(array_unique(array_map('strval', (array) ($input['required_skills'] ?? []))));
        $riskLevel      = strtolower(trim((string) ($input['risk_level'] ?? 'low')));
        $candidates     = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $fileScope      = array_values(array_unique(array_map('strval', (array) ($input['file_scope'] ?? []))));
        $scopeTags      = $this->deriveScopeTags($fileScope);

        $ranked = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $entry = $this->scoreCandidate($candidate, $taskFamily, $requiredSkills, $riskLevel, $scopeTags);
            if ($entry !== null) {
                $ranked[] = $entry;
            }
        }

        // Disqualified candidates sort after every qualified candidate, regardless of fit_score —
        // a muscle with a proven give_back/scope-failure pattern for this family is never primary.
        usort($ranked, static function (array $a, array $b): int {
            if ($a['disqualified_from_primary'] !== $b['disqualified_from_primary']) {
                return $a['disqualified_from_primary'] ? 1 : -1;
            }

            return $b['fit_score'] <=> $a['fit_score'] ?: strcmp($a['muscle_id'], $b['muscle_id']);
        });

        $primaryMuscle = (isset($ranked[0]) && ! $ranked[0]['disqualified_from_primary']) ? $ranked[0]['muscle_id'] : null;
        // Fallback must never be a disqualified candidate either — a repeated give_back/scope-failure
        // muscle sorts after every qualified candidate, so if rank[1] is disqualified there is no
        // second qualified candidate to fall back to; promoting it anyway would silently undo the
        // disqualification the moment the primary becomes unavailable.
        $fallbackMuscle = (isset($ranked[1]) && ! $ranked[1]['disqualified_from_primary']) ? $ranked[1]['muscle_id'] : null;

        $rank = 1;
        foreach ($ranked as &$entry) {
            $entry['rank'] = $rank++;
            unset($entry['disqualified_from_primary']);
        }
        unset($entry);

        return [
            'schema'          => self::SCHEMA,
            'task_family'     => $taskFamily,
            'ranked_muscles'  => $ranked,
            'primary_muscle'  => $primaryMuscle,
            'fallback_muscle' => $fallbackMuscle,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $requiredSkills
     * @return array<string,mixed>|null
     */
    /**
     * Derives coarse scope-family tags from allowed_files/file_scope paths, so a candidate's
     * per-scope history can be checked without requiring exact file matches. E.g.
     * "app/Services/Ai/Foo.php" -> "app/services/ai".
     *
     * @param  list<string>  $fileScope
     * @return list<string>
     */
    private function deriveScopeTags(array $fileScope): array
    {
        $tags = [];
        foreach ($fileScope as $path) {
            $dir = trim(dirname(str_replace('\\', '/', $path)), '/');
            if ($dir === '' || $dir === '.') {
                continue;
            }
            $tags[strtolower($dir)] = true;
        }

        return array_keys($tags);
    }

    /**
     * Wilson score interval lower bound at 95% confidence (z=1.96).
     * Returns 0.5 as neutral prior when total is 0 (no data).
     */
    private function wilsonLowerBound(int $successCount, int $total): float
    {
        if ($total <= 0) {
            return 0.5;
        }

        $z = 1.96;
        $p = $successCount / $total;
        $denominator = 1.0 + $z * $z / $total;
        $center = ($p + $z * $z / (2.0 * $total)) / $denominator;
        $margin = $z * sqrt(($p * (1.0 - $p) + $z * $z / (4.0 * $total)) / $total) / $denominator;

        return round(max(0.0, $center - $margin), 4);
    }

    private function scoreCandidate(array $candidate, string $taskFamily, array $requiredSkills, string $riskLevel, array $scopeTags = []): ?array
    {
        $muscleId = trim((string) ($candidate['muscle_id'] ?? ''));
        if ($muscleId === '') {
            return null;
        }

        $skills       = array_map('strval', (array) ($candidate['skills'] ?? []));
        $capabilities = array_map('strval', (array) ($candidate['capabilities'] ?? []));
        $available    = array_values(array_unique(array_merge($skills, $capabilities)));

        $skillOverlap = $requiredSkills === []
            ? 1.0
            : count(array_intersect($requiredSkills, $available)) / count($requiredSkills);
        $missingSkills = array_values(array_diff($requiredSkills, $available));

        $history     = (array) ($candidate['history'][$taskFamily] ?? []);
        $familyTotal = max(0, (int) ($history['total'] ?? 0));
        $familySucc  = max(0, (int) ($history['success'] ?? 0));
        $familyGiveBack = max(0, (int) ($history['give_back'] ?? 0));
        $familyScopeFailure = max(0, (int) ($history['scope_failure'] ?? 0));
        $familySuccessRate = $familyTotal > 0
            ? $this->wilsonLowerBound($familySucc, $familyTotal)
            : 0.5;

        $recentGiveBackRate = max(0.0, min(1.0, (float) ($candidate['recent_give_back_rate'] ?? 0.0)));
        $recentReliability  = 1.0 - $recentGiveBackRate;

        // Scope-tag preference: a candidate with above-neutral historical success on the
        // task's own file-scope family gets a small bonus, breaking ties toward proven fit.
        $scopeBonus = 0.0;
        $scopeTagMatched = null;
        $candidateHistory = (array) ($candidate['history'] ?? []);
        foreach ($scopeTags as $scopeTag) {
            $scopeHistory = (array) ($candidateHistory[$scopeTag] ?? []);
            $scopeTotal = max(0, (int) ($scopeHistory['total'] ?? 0));
            if ($scopeTotal < self::SCOPE_MIN_SAMPLE_COUNT) {
                continue;
            }
            $scopeSucc = max(0, (int) ($scopeHistory['success'] ?? 0));
            $scopeSuccessRate = $this->wilsonLowerBound($scopeSucc, $scopeTotal);
            if ($scopeSuccessRate > 0.5) {
                $bonus = ($scopeSuccessRate - 0.5) * self::SCOPE_MATCH_WEIGHT;
                if ($bonus > $scopeBonus) {
                    $scopeBonus = $bonus;
                    $scopeTagMatched = $scopeTag;
                }
            }
        }

        $fitScore = round(
            min(1.0, $skillOverlap * self::SKILL_OVERLAP_WEIGHT
                + $familySuccessRate * self::FAMILY_SUCCESS_WEIGHT
                + $recentReliability * self::RECENT_RELIABILITY_WEIGHT
                + $scopeBonus),
            4,
        );

        $riskReasons = [];
        if ($missingSkills !== []) {
            $riskReasons[] = 'missing_required_skills:'.implode(',', $missingSkills);
        }

        $maxRiskLevel = strtolower(trim((string) ($candidate['max_risk_level'] ?? 'high')));
        $riskMismatch = (self::RISK_RANK[$riskLevel] ?? 0) > (self::RISK_RANK[$maxRiskLevel] ?? 2);
        if ($riskMismatch) {
            $riskReasons[] = "risk_level_{$riskLevel}_exceeds_muscle_max_{$maxRiskLevel}";
            $fitScore = min($fitScore, self::RISK_MISMATCH_CAP);
        }

        $disqualified = $familyGiveBack >= self::DISQUALIFY_GIVE_BACK_COUNT
            || $familyScopeFailure >= self::DISQUALIFY_SCOPE_FAILURE_COUNT;
        if ($disqualified) {
            $riskReasons[] = sprintf(
                'repeated_give_back_or_scope_failure_for_family:give_back=%d:scope_failure=%d',
                $familyGiveBack,
                $familyScopeFailure,
            );
        }

        return [
            'muscle_id'                     => $muscleId,
            'fit_score'                     => $fitScore,
            'risk_reasons'                  => $riskReasons,
            'expected_success_confidence'   => round(min(1.0, max(0.0, $fitScore)), 4),
            'disqualified_from_primary'     => $disqualified,
            'scope_match'                   => $scopeTagMatched,
        ];
    }
}
