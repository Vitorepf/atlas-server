<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P2 meta-compounding weight re-derivation.
 *
 * Given the current M weights and the measured contribution scores produced by the
 * contribution attributor, this service PROPOSES re-derived weights. It never mutates
 * configuration or state: it only returns a proposal that an operator/governor must
 * adopt. Re-derivation is gated on longitudinal P5 evidence — any factor missing its
 * P5 self-deception-immunity evidence blocks the whole proposal, leaving the current
 * weights untouched.
 *
 * Re-derivation rule: each factor's re-derived weight is proportional to its current
 * weight multiplied by an effective contribution mass. Effective mass is the measured
 * contribution_score gated by confidence and floored at zero, so negative or noisy
 * contribution can only shrink a factor's share, never grow it. The result is then
 * normalised so the proposed weights sum to exactly 1.0.
 */
final class L8MetaCompoundingWeightRederivationService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l8.meta_compounding.weight_rederivation.v1';

    /**
     * Confidence at or below this floor contributes no re-derivation mass.
     */
    public const CONFIDENCE_FLOOR = 0.5;

    /**
     * Absolute per-factor weight delta (proposed vs current) above this requires a veto slot.
     */
    public const MATERIAL_SHIFT_THRESHOLD = 0.05;

    /**
     * Per-factor weight deltas at or below this are floating-point residue from normalisation,
     * not a real re-derivation. A factor only counts as changed when it moves by more than this.
     */
    public const CHANGE_EPSILON = 1.0e-9;

    /**
     * @param  array<string, int|float>  $current_weights  factor_id => current weight
     * @param list<array{
     *     factor_id?: string,
     *     contribution_score?: int|float,
     *     confidence?: int|float,
     *     p5_evidence_ref?: string,
     *     source_refs?: list<string>
     * }> $contributions measured contribution per factor (from the attributor)
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     proposed_weights: array<string, float>,
     *     changed_factors: list<string>,
     *     operator_veto_required: bool,
     *     rollback_plan: array{strategy: string, restore_weights: array<string, float>, mutation_applied: bool},
     *     blockers: list<string>
     * }
     */
    public function propose(array $current_weights, array $contributions): array
    {
        $baseline = $this->normaliseBaseline($current_weights);

        $blockers = $this->collectBlockers($current_weights, $contributions);

        // Index measured contribution mass by factor_id.
        $effectiveMass = [];
        foreach ($contributions as $contribution) {
            if (! is_array($contribution)) {
                continue;
            }

            $factorId = $contribution['factor_id'] ?? null;
            if (! is_string($factorId) || $factorId === '' || ! array_key_exists($factorId, $baseline)) {
                continue;
            }

            $score = AreaFocusScalarNormalizer::finiteNumericOrZero($contribution['contribution_score'] ?? 0.0);
            $confidence = AreaFocusScalarNormalizer::clampUnit(
                AreaFocusScalarNormalizer::finiteNumericOrZero($contribution['confidence'] ?? 0.0),
            );

            // Negative/noisy contribution floored at zero; low confidence earns no mass.
            $gatedScore = max($score, 0.0);
            $mass = $confidence > self::CONFIDENCE_FLOOR ? $gatedScore * $confidence : 0.0;

            $effectiveMass[$factorId] = ($effectiveMass[$factorId] ?? 0.0) + $mass;
        }

        // When evidence is missing, do not re-derive: the proposal IS the current weights.
        if ($blockers !== []) {
            return $this->blockedProposal($baseline, $blockers);
        }

        $proposed = $this->rederive($baseline, $effectiveMass);

        $changedFactors = [];
        $veto = false;
        foreach ($baseline as $factorId => $currentWeight) {
            $delta = abs($proposed[$factorId] - $currentWeight);
            // Ignore normalisation residue: only a delta beyond floating-point noise is a real change.
            if ($delta > self::CHANGE_EPSILON) {
                // Cast to string: PHP coerces numeric-string array keys back to int, but the
                // contract is list<string>, so a factor id like "10" must stay a string.
                $changedFactors[] = (string) $factorId;
            }
            if ($delta > self::MATERIAL_SHIFT_THRESHOLD) {
                $veto = true;
            }
        }
        // SORT_STRING: numeric-string factor ids ("10","2","100") order lexicographically,
        // not numerically, so the changed_factors ordering is stable and type-faithful.
        sort($changedFactors, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'proposed',
            'proposed_weights' => $proposed,
            'changed_factors' => array_values($changedFactors),
            // Operator always holds the veto slot; a material shift forces it explicitly.
            'operator_veto_required' => true,
            'rollback_plan' => [
                'strategy' => 'restore_current_weights',
                'restore_weights' => $baseline,
                'mutation_applied' => false,
            ],
            'blockers' => [],
        ];
    }

    /**
     * @param  array<string, float>  $baseline
     * @param  array<string, float>  $effectiveMass
     * @return array<string, float>
     */
    private function rederive(array $baseline, array $effectiveMass): array
    {
        // Re-derived raw share = current weight * (1 + effective contribution mass).
        // Factors with no measured contribution keep their current weight as the floor;
        // factors with positive measured contribution grow their share before normalising.
        $raw = [];
        $rawTotal = 0.0;
        foreach ($baseline as $factorId => $currentWeight) {
            $share = $currentWeight * (1.0 + ($effectiveMass[$factorId] ?? 0.0));
            $raw[$factorId] = $share;
            $rawTotal += $share;
        }

        if ($rawTotal <= 0.0) {
            // Degenerate baseline: fall back to an even split that still sums to 1.0.
            return $this->evenSplit(array_keys($baseline));
        }

        return $this->normaliseToOne($raw, $rawTotal);
    }

    /**
     * @param  array<string, int|float>  $current_weights
     * @return array<string, float>
     */
    private function normaliseBaseline(array $current_weights): array
    {
        $floats = [];
        $total = 0.0;
        foreach ($current_weights as $factorId => $weight) {
            $key = (string) $factorId;
            $value = max(AreaFocusScalarNormalizer::finiteNumericOrZero($weight), 0.0);
            $floats[$key] = $value;
            $total += $value;
        }

        if ($floats === []) {
            return [];
        }

        if ($total <= 0.0) {
            return $this->evenSplit(array_keys($floats));
        }

        return $this->normaliseToOne($floats, $total);
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function normaliseToOne(array $weights, float $total): array
    {
        $normalised = [];
        foreach ($weights as $factorId => $value) {
            $normalised[$factorId] = $value / $total;
        }

        // Absorb floating-point residue into the largest factor so the sum is exactly 1.0.
        return $this->forceUnitSum($normalised);
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function forceUnitSum(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_sum($weights);
        $residue = 1.0 - $sum;

        $anchorKey = null;
        $anchorValue = -1.0;
        foreach ($weights as $factorId => $value) {
            if ($value > $anchorValue) {
                $anchorValue = $value;
                $anchorKey = $factorId;
            }
        }

        if ($anchorKey !== null) {
            $weights[$anchorKey] = $anchorValue + $residue;
        }

        return $weights;
    }

    /**
     * @param  list<string>  $factorIds
     * @return array<string, float>
     */
    private function evenSplit(array $factorIds): array
    {
        $count = count($factorIds);
        if ($count === 0) {
            return [];
        }

        $share = 1.0 / $count;
        $even = [];
        foreach ($factorIds as $factorId) {
            $even[$factorId] = $share;
        }

        return $this->forceUnitSum($even);
    }

    /**
     * @param  array<string, float>  $baseline
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     proposed_weights: array<string, float>,
     *     changed_factors: list<string>,
     *     operator_veto_required: bool,
     *     rollback_plan: array{strategy: string, restore_weights: array<string, float>, mutation_applied: bool},
     *     blockers: list<string>
     * }
     */
    private function blockedProposal(array $baseline, array $blockers): array
    {
        sort($blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            // Blocked => no re-derivation; the proposal is the unchanged current weights.
            'proposed_weights' => $baseline,
            'changed_factors' => [],
            'operator_veto_required' => true,
            'rollback_plan' => [
                'strategy' => 'no_change_required',
                'restore_weights' => $baseline,
                'mutation_applied' => false,
            ],
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * @param  array<string, int|float>  $current_weights
     * @param  list<mixed>  $contributions
     * @return list<string>
     */
    private function collectBlockers(array $current_weights, array $contributions): array
    {
        $blockers = [];

        if ($current_weights === []) {
            $blockers[] = 'no_current_weights';
        }

        if ($contributions === []) {
            $blockers[] = 'no_contributions';

            return $blockers;
        }

        foreach ($contributions as $contribution) {
            if (! is_array($contribution) || ! $this->hasP5Evidence($contribution)) {
                $blockers[] = 'missing_p5_evidence';
                break;
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $contribution
     */
    private function hasP5Evidence(array $contribution): bool
    {
        $ref = $contribution['p5_evidence_ref'] ?? null;

        return is_string($ref) && trim($ref) !== '';
    }
}
