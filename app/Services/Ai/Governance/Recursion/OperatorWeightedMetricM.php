<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Recursion;

/**
 * REC-05 — `M_operator` series (MEDIDOR) computed BESIDE the neutral M.
 *
 * The teto de um sistema PESSOAL: improvement with high ΔM on a task class
 * the operator never uses is worth LESS than average ΔM on the operator's
 * daily flow. So the value is not neutral in a 1-operator system — but the
 * neutral M continues published alongside so the divergence between the
 * two is itself a signal (§3062-3064).
 *
 * Weights are DERIVED, never free-text:
 *   - source policy rule (compiled) — the invariant that anchors the weight
 *   - real usage frequency (MAXN-02 / MULTN15) — how often the operator
 *     actually touches this task class in the recent window
 *
 * A weight WITHOUT a compiled rule reference is refused (`invalid`), and
 * a weight change is a NEW series (own `formula_version` snapshot on the
 * caller side — this class does not persist series identity, callers do).
 *
 * Pure. Zero I/O. Provider-safe.
 */
final class OperatorWeightedMetricM
{
    public const FORMULA_VERSION = 'atlas.acos.m_operator.v1';

    /**
     * Compute the operator-weighted M series alongside the neutral M.
     *
     * @param  array<string,float>  $mByTaskClass
     *         The neutral M value per task class (ELEV-02 series slice).
     * @param  array<string,array{rule_ref:string,usage_frequency:mixed,weight_hint?:mixed}>  $weights
     *         Weight input per task class. Each entry MUST carry a
     *         `rule_ref` string (compiled policy rule handle) and a
     *         non-negative `usage_frequency`. `weight_hint` is optional
     *         and multiplied into the derived weight; a caller supplying
     *         a weight_hint without a rule is refused.
     * @return array<string,mixed>
     */
    public static function compute(array $mByTaskClass, array $weights): array
    {
        $errors = [];
        $normalizedWeights = [];
        $totalWeight = 0.0;

        foreach ($weights as $taskClass => $spec) {
            if (! is_string($taskClass) || $taskClass === '') {
                $errors[] = ['field' => 'task_class', 'code' => 'invalid_key', 'reason' => 'task class key must be a non-empty string'];

                continue;
            }
            if (! is_array($spec)) {
                $errors[] = ['field' => $taskClass, 'code' => 'invalid_type', 'reason' => 'weight spec must be an array'];

                continue;
            }
            $ruleRef = is_string($spec['rule_ref'] ?? null) ? trim($spec['rule_ref']) : '';
            if ($ruleRef === '') {
                $errors[] = ['field' => $taskClass.'.rule_ref', 'code' => 'missing', 'reason' => 'weight without a compiled policy rule reference is refused'];

                continue;
            }
            $freq = self::asNonNegativeFloat($spec['usage_frequency'] ?? null);
            if ($freq === null) {
                $errors[] = ['field' => $taskClass.'.usage_frequency', 'code' => 'invalid_type', 'reason' => 'usage_frequency must be a non-negative number (MAXN-02/MULTN15)'];

                continue;
            }
            $hint = 1.0;
            if (array_key_exists('weight_hint', $spec)) {
                $hintCandidate = self::asNonNegativeFloat($spec['weight_hint']);
                if ($hintCandidate === null) {
                    $errors[] = ['field' => $taskClass.'.weight_hint', 'code' => 'invalid_type', 'reason' => 'weight_hint must be a non-negative number when present'];

                    continue;
                }
                $hint = $hintCandidate;
            }
            $rawWeight = $freq * $hint;
            $normalizedWeights[$taskClass] = [
                'rule_ref' => $ruleRef,
                'usage_frequency' => $freq,
                'weight_hint' => $hint,
                'raw_weight' => $rawWeight,
            ];
            $totalWeight += $rawWeight;
        }

        if ($errors !== []) {
            return [
                'formula_version' => self::FORMULA_VERSION,
                'status' => 'invalid',
                'errors' => $errors,
            ];
        }

        // Neutral M — the arithmetic mean over covered task classes so the
        // two series are directly comparable in the digest.
        $covered = array_intersect_key($mByTaskClass, $normalizedWeights);
        if ($covered === [] || $totalWeight <= 0.0) {
            return [
                'formula_version' => self::FORMULA_VERSION,
                'status' => 'insufficient_signal',
                'reason' => $covered === [] ? 'no overlap between M classes and weighted classes' : 'total weight is zero',
                'weights' => $normalizedWeights,
            ];
        }
        $neutralM = array_sum($covered) / count($covered);

        $weighted = 0.0;
        $components = [];
        foreach ($covered as $taskClass => $mValue) {
            $weightSpec = $normalizedWeights[$taskClass];
            $share = $weightSpec['raw_weight'] / $totalWeight;
            $contribution = $share * (float) $mValue;
            $weighted += $contribution;
            $components[$taskClass] = [
                'm' => (float) $mValue,
                'weight_share' => round($share, 6),
                'rule_ref' => $weightSpec['rule_ref'],
                'usage_frequency' => $weightSpec['usage_frequency'],
                'weight_hint' => $weightSpec['weight_hint'],
                'contribution' => round($contribution, 6),
            ];
        }

        return [
            'formula_version' => self::FORMULA_VERSION,
            'status' => 'measured',
            'm_operator' => round($weighted, 6),
            'm_neutral' => round($neutralM, 6),
            'divergence' => round($weighted - $neutralM, 6),
            'components' => $components,
            'covered_task_classes' => array_keys($covered),
            'total_raw_weight' => round($totalWeight, 6),
        ];
    }

    private static function asNonNegativeFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= 0.0 ? $float : null;
    }
}
