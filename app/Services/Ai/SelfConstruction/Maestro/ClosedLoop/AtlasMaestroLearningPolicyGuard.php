<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

/**
 * MAESTRO LEARNING POLICY GUARD — the PÉTREO anti-Goodhart sentinel every learning artifact (mined buckets or a
 * rendered feedback block) must pass before reaching the Replenisher. It REFUSES (throws
 * {@see AtlasMaestroLearningPolicyViolation}) when the artifact:
 *   (a) carries a composite score / ranking field (the loop learns from FACTS, never a scalar),
 *   (b) contains imperative advice tokens (prefer/should/must/avoid/do not/recommend),
 *   (c) carries any bucket below MIN_SUPPORT (no lucky-run learning),
 *   (d) learns from outcomes recorded against a FORBIDDEN scope (MarketingDomain / Aaeos / Forge — outside the
 *       operator-sanctioned Loop+Cortex+Maestro perimeter),
 *   (e) tries to OVERRIDE task quality fields (acceptance_criteria / required_evidence) instead of advising.
 *
 * A clean FACT-only artifact passes silently.
 */
final class AtlasMaestroLearningPolicyGuard
{
    /** Scopes the loop is NOT allowed to learn from / steer (operator backlog perimeter). */
    public const FORBIDDEN_SCOPES = ['MarketingDomain', 'Aaeos', 'Forge'];

    private const IMPERATIVES = ['prefer', 'should', 'must', 'avoid', 'do not', 'recommend'];

    private const TASK_FIELDS = ['acceptance_criteria', 'required_evidence'];

    /**
     * @param  array<mixed>  $artifact
     */
    public function assertSafe(array $artifact): void
    {
        $this->walk($artifact);
    }

    private function walk(mixed $node): void
    {
        if (is_array($node)) {
            // A bucket-like node (carries a total) must meet MIN_SUPPORT.
            if (array_key_exists('total', $node) && is_numeric($node['total'])) {
                $total = (int) $node['total'];
                if ($total < AtlasMaestroOutcomePatternMiner::MIN_SUPPORT || ($node['insufficient_support'] ?? false) === true) {
                    throw AtlasMaestroLearningPolicyViolation::subSupportBucket($total);
                }
            }

            foreach ($node as $key => $value) {
                $k = (string) $key;
                if (preg_match('/\b(score|rank|ranking|composite|weight|weighted)\b/i', $k) === 1) {
                    throw AtlasMaestroLearningPolicyViolation::compositeScore($k);
                }
                if (in_array(strtolower($k), self::TASK_FIELDS, true)) {
                    throw AtlasMaestroLearningPolicyViolation::taskFieldOverride($k);
                }
                $this->walk($value);
            }

            return;
        }

        if (is_string($node)) {
            $lower = strtolower($node);
            foreach (self::IMPERATIVES as $imperative) {
                if (str_contains($lower, $imperative)) {
                    throw AtlasMaestroLearningPolicyViolation::imperativeAdvice($imperative);
                }
            }
            foreach (self::FORBIDDEN_SCOPES as $scope) {
                if (str_contains($node, $scope)) {
                    throw AtlasMaestroLearningPolicyViolation::forbiddenScope($scope);
                }
            }
        }
    }
}
