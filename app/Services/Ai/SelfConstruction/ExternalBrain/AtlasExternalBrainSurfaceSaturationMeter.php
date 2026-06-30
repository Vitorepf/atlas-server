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

    /** All five modes must have non-stale pass records before the surface can be declared exhausted. */
    public const REQUIRED_SEARCH_MODES = ['bug-hunt', 'architecture', 'research', 'simplification', 'proof-gap'];

    private const DEFAULT_YIELD_FLOOR = 0.3;

    private const DEFAULT_SATURATION_THRESHOLD = 0.7;

    private const DEFAULT_MIN_CANDIDATES = 3;

    /**
     * @param  list<array{candidate_id?:string, subsystem?:string, target_path?:string,
     *                    value_mechanism?:string, yield?:float, duplicate?:bool}>  $recentCandidates
     * @param  array{yield_floor?:float, saturation_threshold?:float, min_candidates_for_decision?:int,
     *               mode_passes?:array<string,array{passed?:bool,stale?:bool}>}  $context
     * @return array<string,mixed>
     */
    public function measure(string $surfaceId, array $recentCandidates, array $context = []): array
    {
        $yieldFloor = max(0.0, min(1.0, (float) ($context['yield_floor'] ?? self::DEFAULT_YIELD_FLOOR)));
        $threshold = max(0.0, min(1.0, (float) ($context['saturation_threshold'] ?? self::DEFAULT_SATURATION_THRESHOLD)));
        $minCandidates = max(1, (int) ($context['min_candidates_for_decision'] ?? self::DEFAULT_MIN_CANDIDATES));

        $total = count($recentCandidates);

        if ($total < $minCandidates) {
            return $this->result($surfaceId, self::VERDICT_INSUFFICIENT, 0.0,
                "Only {$total} candidates — need at least {$minCandidates} before a verdict.",
                null, 0.0, 0.0, [], null);
        }

        // Compute rates.
        $duplicateCount = 0;
        $lowYieldCount = 0;
        $subsystemCounts = [];

        foreach ($recentCandidates as $c) {
            if ((bool) ($c['duplicate'] ?? false)) {
                $duplicateCount++;
            }
            $yield = max(0.0, min(1.0, (float) ($c['yield'] ?? 0.0)));
            if ($yield < $yieldFloor) {
                $lowYieldCount++;
            }
            $sub = (string) ($c['subsystem'] ?? '');
            if ($sub !== '') {
                $subsystemCounts[$sub] = ($subsystemCounts[$sub] ?? 0) + 1;
            }
        }

        $duplicateRate = $duplicateCount / $total;
        $lowYieldRate = $lowYieldCount / $total;

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
        if ($duplicateRate >= $threshold && $lowYieldRate >= $threshold) {
            // Block exhausted verdict until all five search modes have non-stale pass records.
            if ($missingModes !== []) {
                return $this->result($surfaceId, self::VERDICT_DEEPEN, $saturationScore,
                    "Rates suggest exhaustion (duplicate_rate={$duplicateRate}, low_yield_rate={$lowYieldRate}) but search modes not fully covered. Run {$nextRecommendedMode} next.",
                    $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode);
            }

            return $this->result($surfaceId, self::VERDICT_EXHAUSTED, $saturationScore,
                "duplicate_rate={$duplicateRate} and low_yield_rate={$lowYieldRate} both exceed threshold={$threshold}. All search modes covered. Surface is spent.",
                $dominantSubsystem, $duplicateRate, $lowYieldRate, [], null);
        }

        if ($duplicateRate >= $threshold) {
            return $this->result($surfaceId, self::VERDICT_ROTATE, $saturationScore,
                "duplicate_rate={$duplicateRate} ≥ {$threshold}: same targets keep reappearing. Rotate to a different surface.",
                $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode);
        }

        if ($lowYieldRate >= $threshold) {
            return $this->result($surfaceId, self::VERDICT_CONSOLIDATE, $saturationScore,
                "low_yield_rate={$lowYieldRate} ≥ {$threshold} but duplicate_rate={$duplicateRate} is healthy. Many unique but low-value ideas — consolidate before expanding.",
                $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode);
        }

        return $this->result($surfaceId, self::VERDICT_DEEPEN, $saturationScore,
            "duplicate_rate={$duplicateRate} and low_yield_rate={$lowYieldRate} both below threshold={$threshold}. Surface still has signal — keep mining.",
            $dominantSubsystem, $duplicateRate, $lowYieldRate, $missingModes, $nextRecommendedMode);
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

    /** @return array<string, mixed> */
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
    ): array {
        return [
            'schema' => self::SCHEMA,
            'surface_id' => $surfaceId,
            'verdict' => $verdict,
            'saturation_score' => round($saturationScore, 4),
            'reasoning' => $reasoning,
            'dominant_subsystem' => $dominantSubsystem,
            'duplicate_rate' => round($duplicateRate, 4),
            'low_yield_rate' => round($lowYieldRate, 4),
            'missing_modes' => $missingModes,
            'next_recommended_mode' => $nextRecommendedMode,
        ];
    }
}
