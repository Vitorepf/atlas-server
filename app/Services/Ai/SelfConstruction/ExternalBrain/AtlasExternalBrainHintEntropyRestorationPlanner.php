<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure entropy planner. Converts low or zero hint entropy into concrete
 * diversity pressure on the next task batch, preventing Goodhart drift where
 * the brain repeats the same path family indefinitely.
 *
 * Pressure levels (by hint_entropy):
 *   critical (< 0.30) — strong bans + high target floor (0.65)
 *   moderate (0.30–0.60) — soft bans + medium floor (0.45)
 *   low (> 0.60)     — light constraints + minimum floor (0.30)
 *
 * A family is BANNED when it appears in more than 50% of recent_batch_families
 * (concentration ban) AND entropy is below the moderate threshold.
 *
 * The dominant high-yield vein is preserved as an allowed_exception when
 * dominant_vein_compounding = true, but at least one orthogonal probe family
 * (a starved_path that differs from the dominant vein) is always required.
 *
 * AC4: result always includes target_entropy_floor, required_hint_families,
 *      banned_repeated_families, and allowed_exceptions.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainHintEntropyRestorationPlanner
{
    public const SCHEMA = 'atlas.external_brain.hint_entropy_restoration_planner.v1';

    private const ENTROPY_CRITICAL_THRESHOLD = 0.30;
    private const ENTROPY_MODERATE_THRESHOLD = 0.60;

    private const TARGET_FLOOR_CRITICAL = 0.65;
    private const TARGET_FLOOR_MODERATE = 0.45;
    private const TARGET_FLOOR_LOW      = 0.30;

    private const CONCENTRATION_BAN_FRACTION = 0.50;

    /**
     * @param  array{
     *   hint_entropy?: float,
     *   recent_batch_families?: list<string>,
     *   starved_paths?: list<string>,
     *   dominant_vein?: string|null,
     *   dominant_vein_compounding?: bool,
     *   batch_size?: int,
     * }  $input
     * @return array{schema:string, target_entropy_floor:float, required_hint_families:list<string>, banned_repeated_families:list<string>, allowed_exceptions:list<string>}
     */
    public function plan(array $input): array
    {
        $hintEntropy          = max(0.0, min(1.0, (float) ($input['hint_entropy']           ?? 1.0)));
        $recentFamilies       = array_values(array_filter(array_map('trim', (array) ($input['recent_batch_families'] ?? []))));
        $starvedPaths         = array_values(array_filter(array_map('trim', (array) ($input['starved_paths']         ?? []))));
        $dominantVein         = trim((string) ($input['dominant_vein']                        ?? ''));
        $dominantCompounding  = (bool) ($input['dominant_vein_compounding']                   ?? false);

        $isCritical = $hintEntropy < self::ENTROPY_CRITICAL_THRESHOLD;
        $isModerate = $hintEntropy < self::ENTROPY_MODERATE_THRESHOLD;

        $targetFloor = match (true) {
            $isCritical => self::TARGET_FLOOR_CRITICAL,
            $isModerate => self::TARGET_FLOOR_MODERATE,
            default     => self::TARGET_FLOOR_LOW,
        };

        $banned = $this->computeBannedFamilies($recentFamilies, $isModerate);

        $allowedExceptions = [];
        if ($dominantVein !== '' && $dominantCompounding) {
            $allowedExceptions[] = $dominantVein;
        }

        $required = $this->computeRequiredFamilies($starvedPaths, $banned, $dominantVein, $dominantCompounding, $isCritical);

        return [
            'schema'                    => self::SCHEMA,
            'target_entropy_floor'      => $targetFloor,
            'required_hint_families'    => $required,
            'banned_repeated_families'  => $banned,
            'allowed_exceptions'        => $allowedExceptions,
        ];
    }

    /** @return list<string> */
    private function computeBannedFamilies(array $recentFamilies, bool $shouldBan): array
    {
        if (! $shouldBan || $recentFamilies === []) {
            return [];
        }

        $total  = count($recentFamilies);
        $counts = array_count_values($recentFamilies);
        $banned = [];

        foreach ($counts as $family => $count) {
            if (($count / $total) > self::CONCENTRATION_BAN_FRACTION) {
                $banned[] = $family;
            }
        }

        sort($banned);

        return $banned;
    }

    /**
     * @param  list<string>  $starvedPaths
     * @param  list<string>  $banned
     * @return list<string>
     */
    private function computeRequiredFamilies(
        array $starvedPaths,
        array $banned,
        string $dominantVein,
        bool $dominantCompounding,
        bool $isCritical,
    ): array {
        $required = [];

        // Include starved paths that are not banned
        foreach ($starvedPaths as $path) {
            if (! in_array($path, $banned, true)) {
                $required[] = $path;
            }
        }

        // Always require at least one orthogonal probe (a starved path different from dominant vein)
        $hasOrthogonalProbe = false;
        foreach ($required as $family) {
            if ($family !== $dominantVein) {
                $hasOrthogonalProbe = true;
                break;
            }
        }

        if (! $hasOrthogonalProbe && $isCritical) {
            // If critical and no orthogonal probe yet, add 'orthogonal_probe_required' as a signal
            $required[] = 'orthogonal_probe_required';
        }

        // If dominant vein is compounding, it is allowed via exceptions, not required.
        // Remove it from required (it's covered by allowed_exceptions).
        if ($dominantVein !== '' && $dominantCompounding) {
            $required = array_values(array_filter($required, fn (string $f): bool => $f !== $dominantVein));
        }

        return $required;
    }
}
