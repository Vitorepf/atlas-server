<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure budget manager. Evaluates whether the model-amplifier subsystem is within
 * its complexity budget and recommends simplifications.
 *
 * Input facts:
 *   components — list of {id, type, usage_score, slo_met, has_value_proof}.
 *     type          — 'scaffold'|'gate'|'judge'|'telemetry'.
 *     usage_score   — float 0–1 (how actively used).
 *     slo_met       — bool (component meets its SLO).
 *     has_value_proof — bool (component has proven downstream value).
 *   budgets    — optional {scaffold, gate, judge, telemetry} with per-type limits.
 *
 * AC2 — Over-complexity flagged when count of active components of a type exceeds its limit:
 *   defaults: scaffold=10, gate=8, judge=5, telemetry=20.
 *
 * AC3 — Per-component recommendation:
 *   'retire'      — usage_score < 0.20 OR (slo_met=false AND has_value_proof=false).
 *   'consolidate' — (usage_score >= 0.20 AND slo_met=false) OR usage_score in [0.20, 0.70).
 *   'keep'        — usage_score >= 0.70 AND slo_met=true.
 *   Priority: retire > keep > consolidate.
 *
 * AC4 outputs: within_budget, over_budget_dimensions, recommended_simplifications,
 *   preserved_items.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainAmplifierComplexityBudget
{
    public const SCHEMA = 'atlas.external_brain.amplifier_complexity_budget.v1';

    private const DEFAULTS = ['scaffold' => 10, 'gate' => 8, 'judge' => 5, 'telemetry' => 20];

    private const RETIRE_USAGE_THRESHOLD = 0.20;
    private const KEEP_USAGE_THRESHOLD   = 0.70;

    private const VALID_TYPES = ['scaffold', 'gate', 'judge', 'telemetry'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $rawComponents = is_array($facts['components'] ?? null) ? $facts['components'] : [];
        $rawBudgets    = is_array($facts['budgets']    ?? null) ? $facts['budgets']    : [];

        $limits = $this->resolveLimits($rawBudgets);

        // Count active components per type and collect metadata.
        $countByType    = array_fill_keys(self::VALID_TYPES, 0);
        $components     = [];

        foreach ($rawComponents as $raw) {
            $id    = (string) ($raw['id']   ?? '');
            $type  = strtolower(trim((string) ($raw['type'] ?? '')));
            if (! in_array($type, self::VALID_TYPES, true)) {
                continue;
            }
            $usage      = max(0.0, min(1.0, (float) ($raw['usage_score']    ?? 0.0)));
            $sloMet     = (bool) ($raw['slo_met']         ?? true);
            $valueProof = (bool) ($raw['has_value_proof'] ?? false);

            $countByType[$type]++;
            $components[] = compact('id', 'type', 'usage', 'sloMet', 'valueProof');
        }

        // AC2: detect over-budget dimensions.
        $overBudget = [];
        foreach (self::VALID_TYPES as $type) {
            if ($countByType[$type] > $limits[$type]) {
                $overBudget[] = [
                    'type'    => $type,
                    'current' => $countByType[$type],
                    'limit'   => $limits[$type],
                ];
            }
        }

        // AC3: per-component recommendations.
        $simplifications = [];
        $preserved       = [];

        foreach ($components as $c) {
            [$rec, $reason] = $this->recommend($c['usage'], $c['sloMet'], $c['valueProof']);
            if ($rec === 'keep') {
                $preserved[] = $c['id'];
            } else {
                $simplifications[] = [
                    'id'             => $c['id'],
                    'type'           => $c['type'],
                    'recommendation' => $rec,
                    'reason'         => $reason,
                ];
            }
        }

        return [
            'schema_version'             => self::SCHEMA,
            'within_budget'              => empty($overBudget),
            'over_budget_dimensions'     => $overBudget,
            'recommended_simplifications' => $simplifications,
            'preserved_items'            => $preserved,
        ];
    }

    private function resolveLimits(array $raw): array
    {
        $limits = self::DEFAULTS;
        foreach (self::VALID_TYPES as $type) {
            if (array_key_exists($type, $raw) && is_int($raw[$type])) {
                $limits[$type] = max(0, $raw[$type]);
            }
        }

        return $limits;
    }

    private function recommend(float $usage, bool $sloMet, bool $valueProof): array
    {
        // Retire: low usage OR no SLO + no proof.
        if ($usage < self::RETIRE_USAGE_THRESHOLD || (! $sloMet && ! $valueProof)) {
            return ['retire', 'low_usage_or_no_value_proof'];
        }

        // Keep: high usage AND meets SLO.
        if ($usage >= self::KEEP_USAGE_THRESHOLD && $sloMet) {
            return ['keep', 'high_usage_and_slo_met'];
        }

        // Consolidate: everything else (mid-usage or SLO miss with some value).
        return ['consolidate', 'moderate_usage_or_slo_miss'];
    }
}
