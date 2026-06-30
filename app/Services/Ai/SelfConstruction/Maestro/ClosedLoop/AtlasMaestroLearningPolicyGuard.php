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

    /** String fragments that indicate proxy / cosmetic-wrapper work (Goodhart bait). */
    private const PROXY_PATTERNS = ['cosmetic wrapper', 'template farm', 'template_farm', 'proxy work', 'no-op refactor', 'boilerplate only', 'noop refactor'];

    /** Human-in-the-loop signals that would break 24/7 unattended operation. */
    private const HUMAN_DEPENDENCY_PATTERNS = ['requires human', 'human approval', 'human review', 'awaiting operator', 'manual intervention', 'human-in-the-loop'];

    /** External provider mandate signals that would create uncontrolled spend. */
    private const EXTERNAL_PROVIDER_PATTERNS = ['requires codex', 'requires claude', 'call provider', 'provider required', 'external ai call', 'mandatory provider'];

    /** Quota-padding signals that inflate completion numbers without real delivery. */
    private const QUOTA_PADDING_PATTERNS = ['quota padding', 'pad count', 'filler task', 'quota pad', 'filler_task'];

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
                // allowed_files that are ALL under tests/ indicate a test-only task (proxy work).
                if (strtolower($k) === 'allowed_files' && is_array($value) && count($value) > 0) {
                    $testPaths = array_filter($value, static fn ($f) => is_string($f) && str_starts_with($f, 'tests/'));
                    if (count($testPaths) === count($value)) {
                        throw new AtlasMaestroLearningPolicyViolation('test_only_allowed_files', 'learning artifact recommends task whose allowed_files are all test paths (proxy work)');
                    }
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
            foreach (self::PROXY_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    throw new AtlasMaestroLearningPolicyViolation('proxy_work', "learning artifact suggests proxy/cosmetic work: {$pattern}");
                }
            }
            foreach (self::HUMAN_DEPENDENCY_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    throw new AtlasMaestroLearningPolicyViolation('human_dependency', "learning artifact implies human dependency in steady state: {$pattern}");
                }
            }
            foreach (self::EXTERNAL_PROVIDER_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    throw new AtlasMaestroLearningPolicyViolation('external_provider_dependency', "learning artifact mandates external provider call: {$pattern}");
                }
            }
            foreach (self::QUOTA_PADDING_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    throw new AtlasMaestroLearningPolicyViolation('quota_padding', "learning artifact suggests quota padding: {$pattern}");
                }
            }
        }
    }
}
