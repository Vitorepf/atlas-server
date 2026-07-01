<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * Pure policy that requires promotion to show measurable compounding impact or
 * autonomy unlock evidence, rejecting green but low-leverage work.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasGoalValueDecisionPolicy
{
    public const SCHEMA = 'atlas.goal_value.decision_policy.v1';

    public const DECISION_PROMOTE = 'promote';
    public const DECISION_REVISE = 'revise';
    public const DECISION_DEFER = 'defer';

    /**
     * @param  array{
     *   verification_status?:string,
     *   has_implementation_evidence?:bool,
     *   compounding_metric?:?string,
     *   autonomy_unlock?:bool,
     *   downstream_consumer_evidence?:bool,
     * }  $candidate
     * @return array{
     *   schema:string,
     *   decision:string,
     *   reasons:list<string>,
     * }
     */
    public function decide(array $candidate): array
    {
        $verificationStatus = (string) ($candidate['verification_status'] ?? '');
        $hasImplEvidence = (bool) ($candidate['has_implementation_evidence'] ?? false);
        $compoundingMetric = $candidate['compounding_metric'] ?? null;
        $autonomyUnlock = (bool) ($candidate['autonomy_unlock'] ?? false);
        $downstreamConsumer = (bool) ($candidate['downstream_consumer_evidence'] ?? false);

        // Must be verified green first
        if ($verificationStatus !== 'green') {
            return $this->envelope(self::DECISION_REVISE, ['verification_not_green:' . $verificationStatus]);
        }

        // Must have implementation evidence
        if (! $hasImplEvidence) {
            return $this->envelope(self::DECISION_REVISE, ['missing:implementation_evidence']);
        }

        // Require at least one compounding impact signal
        $hasCompoundingImpact = ($compoundingMetric !== null && is_string($compoundingMetric) && $compoundingMetric !== '')
            || $autonomyUnlock
            || $downstreamConsumer;

        if (! $hasCompoundingImpact) {
            return $this->envelope(self::DECISION_DEFER, ['green_but_low_leverage: no compounding metric, no autonomy unlock, no downstream consumer']);
        }

        $reasons = ['verified_green', 'has_implementation_evidence'];
        if ($compoundingMetric !== null && $compoundingMetric !== '') {
            $reasons[] = 'compounding_metric:' . $compoundingMetric;
        }
        if ($autonomyUnlock) {
            $reasons[] = 'autonomy_unlock';
        }
        if ($downstreamConsumer) {
            $reasons[] = 'downstream_consumer_evidence';
        }

        return $this->envelope(self::DECISION_PROMOTE, $reasons);
    }

    /** @param  list<string>  $reasons */
    private function envelope(string $decision, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }
}
