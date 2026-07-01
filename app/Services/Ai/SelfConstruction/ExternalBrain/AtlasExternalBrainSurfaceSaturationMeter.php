<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects when a search surface is getting exhausted, duplicated, or over-mined.
 *
 * Compares recent candidates on subsystem, target_path, value_mechanism, and yield to
 * advise the brain when to rotate, deepen, consolidate, or stop.
 *
 * VERDICTS (no vague numeric score, always an actionable word):
 *   exhausted_with_evidence — both duplicate_rate AND low_yield_rate ≥ saturation_threshold.
 *                             Surface is genuinely spent; stop mining here.
 *   rotate                  — duplicate_rate ≥ threshold (same targets keep appearing).
 *                             Switch to a different surface before padding.
 *   consolidate             — low_yield_rate ≥ threshold but duplicate_rate is healthy.
 *                             Many unique ideas, all low-value — merge or drop before expanding.
 *   deepen                  — surface looks healthy (low dupe, acceptable yield).
 *                             Continue mining; there is still signal here.
 *   insufficient_data       — fewer than min_candidates_for_decision; no verdict yet.
 *
 * MECHANISM SATURATION (opt-in via context.mechanism_saturation_enabled=true; measures LEVERAGE
 * MECHANISM diversity, not raw candidate volume): when enabled, if the dominant value_mechanism
 * accounts for ≥ saturation_threshold of all candidates, the surface is treated as
 * mechanism-saturated even when duplicate_rate/low_yield_rate individually read healthy —
 * repeatedly mining the same lever, however many distinct files/targets it touches, is still
 * exhaustion. Conversely, few candidates spread across distinct high-leverage mechanisms never
 * trigger mechanism saturation — diversity of mechanism, not candidate count, keeps the surface
 * open. Opt-in (default false) so existing callers that reuse one value_mechanism label as a
 * generic tag, not a genuine leverage signal, keep prior behavior.
 *
 * INPUT recentCandidates:
 *   list<{ candidate_id?:string, subsystem?:string, target_path?:string,
 *          value_mechanism?:string, yield?:float, duplicate?:bool }>
 *
 * INPUT context:
 *   { yield_floor?:float, saturation_threshold?:float, min_candidates_for_decision?:int }
 *
 * OUTPUT:
 *   { schema, surface_id, verdict, saturation_score, reasoning,
 *     dominant_subsystem:string|null, duplicate_rate, low_yield_rate,
 *     missing_modes:list<string>, next_recommended_mode:string|null }
 *
 * SATURATION GATE — exhausted_with_evidence requires explicit pass records for ALL FIVE
 * search modes: bug-hunt, architecture, research, simplification, proof-gap.
 * Missing or stale pass records keep the surface open (verdict → deepen) and surface
 * next_recommended_mode so the brain knows what to run next.
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainSurfaceSaturationMeter
{
    public const SCHEMA = 'atlas.external_brain.surface_saturation_meter.v1';

    public const VERDICT_ROTATE = 'rotate';

    public const VERDICT_DEEPEN = 'deepen';

    public const VERDICT_CONSOLIDATE = 'consolidate';

    public const VERDICT_EXHAUSTED = 'exhausted_with_evidence';

    public const VERDICT_INSUFFICIENT = 'insufficient_data';

    /** AC2: opt-in verdict when high-dupe+high-yield surface still lacks required mode coverage. */
    public const VERDICT_UNDER_EVIDENCED = 'under_evidenced';

    /** All five modes must have non-stale pass records before the surface can be declared exhausted. */
    public const REQUIRED_SEARCH_MODES = ['bug-hunt', 'architecture', 'research', 'simplification', 'proof-gap'];

    private const DEFAULT_YIELD_FLOOR = 0.3;

    private const DEFAULT_SATURATION_THRESHOLD = 0.7;

    private const DEFAULT_MIN_CANDIDATES = 3;

    /**
     * @param  list<array{candidate_id?:string, subsystem?:string, target_path?:string,
     *                    value_mechanism?:string, yield?:float, duplicate?:bool}>  $recentCandidates
     * @param  array{yield_floor?:float, saturation_threshold?:float, min_candidates_for_decision?:int,
     *               mode_passes?:array<string,array{passed?:bool,stale?:bool}>,
     *               strict_mode_evidence?:bool, require_value_proof_evidence?:bool,
     *               min_value_proof_count?:int, min_value_proof_rate?:float}  $context
     * @return array<string,mixed>
     */
    public function measure(string $surfaceId, array $recentCandidates, array $context = []): array
    {
        $yieldFloor          = max(0.0, min(1.0, (float) ($context['yield_floor']               ?? self::DEFAULT_YIELD_FLOOR)));
        $threshold           = max(0.0, min(1.0, (float) ($context['saturation_threshold']      ?? self::DEFAULT_SATURATION_THRESHOLD)));
        $minCandidates       = max(1, (int) ($context['min_candidates_for_decision']            ?? self::DEFAULT_MIN_CANDIDATES));
        $strictModeEvidence  = (bool) ($context['strict_mode_evidence'] ?? false);
        $requireValueProof   = (bool) ($context['require_value_proof_evidence'] ?? false);
        $mechanismSaturationEnabled = (bool) ($context['mechanism_saturation_enabled'] ?? false);
        $minValueProofCount  = max(0, (int) ($context['min_value_proof_count'] ?? 1));
        $minValueProofRate   = max(0.0, min(1.0, (float) ($context['min_value_proof_rate'] ?? 0.0)));

        $total = count($recentCandidates);

        if ($total < $minCandidates) {
            $knownMechanismsEarly = array_values(array_map('strval', (array) ($context['known_leverage_mechanisms'] ?? [])));

            return $this->result($surfaceId, self::VERDICT_INSUFFICIENT, 0.0,
                "Only {$total} candidates — need at least {$minCandidates} before a verdict.",
                null, 0.0, 0.0, [], null, $knownMechanismsEarly, null);
        }

        // Compute rates.
        $duplicateCount = 0;
        $lowYieldCount = 0;
        $valueProofCount = 0;
        $subsystemCounts = [];
        $mechanismCounts = [];

        foreach ($recentCandidates as $c) {
            if ((bool) ($c['duplicate'] ?? false)) {
                $duplicateCount++;
            }
            $yield = max(0.0, min(1.0, (float) ($c['yield'] ?? 0.0)));
            if ($yield < $yieldFloor) {
                $lowYieldCount++;
            }
            if ((bool) ($c['value_proof'] ?? false)) {
                $valueProofCount++;
            }
            $sub = (string) ($c['subsystem'] ?? '');
            if ($sub !== '') {
                $subsystemCounts[$sub] = ($subsystemCounts[$sub] ?? 0) + 1;
            }
            $mechanism = (string) ($c['value_mechanism'] ?? '');
            if ($mechanism !== '') {
                $mechanismCounts[$mechanism] = ($mechanismCounts[$mechanism] ?? 0) + 1;
            }
        }

        // Mechanism concentration: the dominant mechanism's share of all candidates. High
        // concentration means the surface keeps yielding the same lever regardless of how many
        // distinct files/targets it touched.
        $dominantMechanismCount = $mechanismCounts !== [] ? max($mechanismCounts) : 0;
        $mechanismConcentration = $total > 0 ? $dominantMechanismCount / $total : 0.0;
        $mechanismSaturated = $mechanismSaturationEnabled && $mechanismConcentration >= $threshold;

        $knownMechanisms = array_values(array_map('strval', (array) ($context['known_leverage_mechanisms'] ?? [])));
        $seenMechanisms = array_keys($mechanismCounts);
        $remainingMechanisms = array_values(array_diff($knownMechanisms, $seenMechanisms));
        $nextProbeHint = $remainingMechanisms !== []
            ? "probe distinct leverage mechanism: {$remainingMechanisms[0]}"
            : ($mechanismSaturated ? 'no untried leverage mechanism configured — widen known_leverage_mechanisms before continuing' : null);

        $duplicateRate = $duplicateCount / $total;
        $lowYieldRate = $lowYieldCount / $total;
        $valueProofRate = $valueProofCount / $total;
        // A min_value_proof_rate of 0.0 means "no rate requirement configured" — it must NOT act
        // as a trivially-always-satisfied alternate path (rate is never negative).
        $countSatisfied = $valueProofCount >= $minValueProofCount;
        $rateSatisfied = $minValueProofRate > 0.0 && $valueProofRate >= $minValueProofRate;
        $valueProofInsufficient = $requireValueProof && ! $countSatisfied && ! $rateSatisfied;

        // Dominant subsystem (most common, null if no subsystem info).
        $dominantSubsystem = null;
        if ($subsystemCounts !== []) {
            arsort($subsystemCounts);
            $dominantSubsystem = (string) array_key_first($subsystemCounts);
        }

        // Saturation score: weighted average of both rates.
        $saturationScore = ($duplicateRate + $lowYieldRate) / 2.0;

        // Mode-coverage check — always computed so the caller knows what's missing.
        $modePasses = is_array($context['mode_passes'] ?? null) ? $context['mode_passes'] : [];
        $missingModes = $this->missingSearchModes($modePasses);
        $nextRecommendedMode = $missingModes[0] ?? null;

        // Verdict decision tree.
        if (($duplicateRate >= $threshold && $lowYieldRate >= $threshold) || $mechanismSaturated) {
            // Block exhausted verdict until all five search modes have non-stale pass records,
            // AND (when required) enough value-proof evidence backs the duplicate/low-yield rates.
            if ($missingModes !== [] || $valueProofInsufficient) {
                // AC2: strict_mode_evidence=true → under_evidenced; default keeps deepen for backward compat.
                $blockedVerdict = ($strictModeEvidence || $valueProofInsufficient) ? self::VERDICT_UNDER_EVIDENCED : self::VERDICT_DEEPEN;
                $reason = $mechanismSaturated
                    ? "Dominant mechanism accounts for {$mechanismConcentration} of candidates (≥{$threshold}) but "
                    : "Rates suggest exhaustion (duplicate_rate={$duplicateRate}, low_yield_rate={$lowYieldRate}) but ";
                $reason .= $valueProofInsufficient
                    ? "value-proof evidence is thin (count={$valueProofCount} < {$minValueProofCount}, rate={$valueProofRate} < {$minValueProofRate})."
                    : "search modes not fully covered. Run {$nextRecommendedMode} next.";

                return $this->result($surfaceId, $blockedVerdict, $saturationScore, $reason,
                    $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode,
                    $remainingMechanisms, $nextProbeHint);
            }

            $reason = $mechanismSaturated
                ? "dominant mechanism concentration={$mechanismConcentration} ≥ threshold={$threshold}. Same leverage mechanism keeps repeating. All search modes covered. Surface is spent."
                : "duplicate_rate={$duplicateRate} and low_yield_rate={$lowYieldRate} both exceed threshold={$threshold}. All search modes covered. Surface is spent.";

            return $this->result($surfaceId, self::VERDICT_EXHAUSTED, $saturationScore, $reason,
                $dominantSubsystem, $duplicateRate, $lowYieldRate, [], null, $remainingMechanisms, $nextProbeHint);
        }

        if ($duplicateRate >= $threshold) {
            return $this->result($surfaceId, self::VERDICT_ROTATE, $saturationScore,
                "duplicate_rate={$duplicateRate} ≥ {$threshold}: same targets keep reappearing. Rotate to a different surface.",
                $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode,
                $remainingMechanisms, $nextProbeHint);
        }

        if ($lowYieldRate >= $threshold) {
            return $this->result($surfaceId, self::VERDICT_CONSOLIDATE, $saturationScore,
                "low_yield_rate={$lowYieldRate} ≥ {$threshold} but duplicate_rate={$duplicateRate} is healthy. Many unique but low-value ideas — consolidate before expanding.",
                $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode,
                $remainingMechanisms, $nextProbeHint);
        }

        return $this->result($surfaceId, self::VERDICT_DEEPEN, $saturationScore,
            "duplicate_rate={$duplicateRate} and low_yield_rate={$lowYieldRate} both below threshold={$threshold}. Surface still has signal — keep mining.",
            $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode,
            $remainingMechanisms, $nextProbeHint);
    }

    /** @return list<string> */
    private function missingSearchModes(array $modePasses): array
    {
        $missing = [];
        foreach (self::REQUIRED_SEARCH_MODES as $mode) {
            $record = $modePasses[$mode] ?? null;
            if (! is_array($record) || ! ($record['passed'] ?? false) || ($record['stale'] ?? false)) {
                $missing[] = $mode;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $remainingMechanisms
     * @return array<string, mixed>
     */
    private function result(
        string $surfaceId,
        string $verdict,
        float $saturationScore,
        string $reasoning,
        ?string $dominantSubsystem,
        float $duplicateRate,
        float $lowYieldRate,
        array $missingModes,
        ?string $nextRecommendedMode,
        array $remainingMechanisms = [],
        ?string $nextProbeHint = null,
    ): array {
        $evidenceNeeded = $missingModes !== []
            ? 'non-stale pass records required for: ' . implode(', ', $missingModes)
            : 'all required search modes covered';

        return [
            'schema'                => self::SCHEMA,
            'surface_id'            => $surfaceId,
            'verdict'               => $verdict,
            'recommendation'        => $this->deriveRecommendation($verdict),
            'saturation_score'      => round($saturationScore, 4),
            'reasoning'             => $reasoning,
            'dominant_subsystem'    => $dominantSubsystem,
            'duplicate_rate'        => round($duplicateRate, 4),
            'low_yield_rate'        => round($lowYieldRate, 4),
            'missing_modes'         => $missingModes,
            'next_recommended_mode' => $nextRecommendedMode,
            'remaining_mechanisms'  => $remainingMechanisms,
            'next_probe_hint'       => $nextProbeHint,
            'next_search_plan'      => [
                'ranked_modes'    => $missingModes,
                'evidence_needed' => $evidenceNeeded,
                'stop_condition'  => 'all required search modes must have non-stale pass records',
            ],
        ];
    }

    private function deriveRecommendation(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_EXHAUSTED       => 'pivot',
            self::VERDICT_ROTATE          => 'pivot',
            self::VERDICT_CONSOLIDATE     => 'consolidate',
            self::VERDICT_UNDER_EVIDENCED => 'deepen_second_pass',
            default                       => 'continue',
        };
    }
}
