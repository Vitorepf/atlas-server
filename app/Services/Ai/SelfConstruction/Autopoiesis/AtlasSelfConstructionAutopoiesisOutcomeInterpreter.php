<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autopoiesis;

/**
 * Pure interpreter — classifies an experiment OUTCOME from FACTS into one of:
 *   promote_candidate | retry_with_changes | quarantine | reject
 *
 * INPUT FACTS:
 *   { verification_passed:bool, evidence_ref:string, real_leverage_proof?:bool,
 *     proxy_only_signal?:bool, regression_detected?:bool, retry_count?:int,
 *     residual_risk_count?:int }
 *
 * VERDICT RULES (evaluation order):
 *   quarantine          — regression_detected=true OR retry_count >= QUARANTINE_RETRY_THRESHOLD
 *   reject              — verification_passed=false OR evidence_ref empty (no learning without evidence)
 *   retry_with_changes  — verification_passed=true AND (proxy_only_signal=true OR residual_risk_count > 0)
 *   promote_candidate   — verification_passed=true AND real_leverage_proof=true AND no proxy signal AND
 *                         no residual risk
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (reasons sorted).
 *   - PROXY-only wins are NEVER promoted — they go to retry_with_changes.
 *   - No verdict other than promote_candidate is ever interpreted as 'promote'.
 *   - NO scalar score.
 */
final class AtlasSelfConstructionAutopoiesisOutcomeInterpreter
{
    public const SCHEMA = 'atlas.autopoiesis.outcome_interpreter.v1';

    public const VERDICT_PROMOTE = 'promote_candidate';

    public const VERDICT_RETRY = 'retry_with_changes';

    public const VERDICT_QUARANTINE = 'quarantine';

    public const VERDICT_REJECT = 'reject';

    public const QUARANTINE_RETRY_THRESHOLD = 3;

    /**
     * @param  array{
     *     verification_passed?:bool,
     *     evidence_ref?:string,
     *     real_leverage_proof?:bool,
     *     proxy_only_signal?:bool,
     *     regression_detected?:bool,
     *     retry_count?:int,
     *     residual_risk_count?:int
     * }  $facts
     * @return array{schema:string, verdict:string, reasons:list<string>}
     */
    public function interpret(array $facts): array
    {
        $verPassed = (bool) ($facts['verification_passed'] ?? false);
        $evidence = (string) ($facts['evidence_ref'] ?? '');
        $realLeverage = (bool) ($facts['real_leverage_proof'] ?? false);
        $proxyOnly = (bool) ($facts['proxy_only_signal'] ?? false);
        $regression = (bool) ($facts['regression_detected'] ?? false);
        $retry = (int) ($facts['retry_count'] ?? 0);
        $residualRisk = (int) ($facts['residual_risk_count'] ?? 0);

        $reasons = [];

        if ($regression) {
            $reasons[] = 'quarantine:regression_detected';
        }
        if ($retry >= self::QUARANTINE_RETRY_THRESHOLD) {
            $reasons[] = 'quarantine:retry_threshold_exceeded:'.$retry;
        }
        if ($reasons !== []) {
            sort($reasons, SORT_STRING);

            return $this->envelope(self::VERDICT_QUARANTINE, $reasons);
        }

        if (! $verPassed) {
            return $this->envelope(self::VERDICT_REJECT, ['reject:verification_not_passed']);
        }
        if ($evidence === '') {
            return $this->envelope(self::VERDICT_REJECT, ['reject:evidence_ref_missing']);
        }

        if ($proxyOnly || $residualRisk > 0) {
            $r = [];
            if ($proxyOnly) {
                $r[] = 'retry:proxy_only_signal';
            }
            if ($residualRisk > 0) {
                $r[] = 'retry:residual_risk:'.$residualRisk;
            }
            sort($r, SORT_STRING);

            return $this->envelope(self::VERDICT_RETRY, $r);
        }

        if (! $realLeverage) {
            return $this->envelope(self::VERDICT_RETRY, ['retry:real_leverage_proof_missing']);
        }

        return $this->envelope(self::VERDICT_PROMOTE, ['promote:real_leverage_proof+evidence']);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, verdict:string, reasons:list<string>}
     */
    private function envelope(string $verdict, array $reasons): array
    {
        return ['schema' => self::SCHEMA, 'verdict' => $verdict, 'reasons' => $reasons];
    }
}
