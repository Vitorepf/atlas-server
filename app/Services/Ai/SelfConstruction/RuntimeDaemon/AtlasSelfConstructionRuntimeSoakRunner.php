<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Pure soak runner that validates a virtual runtime window requires enough ticks,
 * recovered cycles, held cycles, and no forbidden dependencies before declaring
 * green soak status.
 *
 * Status is "partial" unless minimum diversity floors are met.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionRuntimeSoakRunner
{
    public const SCHEMA = 'atlas.self_construction.runtime_soak_runner.v1';

    public const STATUS_GREEN = 'green';
    public const STATUS_PARTIAL = 'partial';

    private const MIN_GREEN_TICKS = 10;
    private const MIN_RECOVERED_CYCLES = 1;
    private const MIN_HELD_CYCLES = 1;

    /**
     * @param  array{
     *   total_ticks?:int,
     *   green_ticks?:int,
     *   recovered_cycles?:int,
     *   held_cycles?:int,
     *   forbidden_dependency_hits?:int,
     *   min_green_ticks?:int,
     *   min_recovered_cycles?:int,
     *   min_held_cycles?:int,
     * }  $window
     * @return array{
     *   schema:string,
     *   status:string,
     *   reasons:list<string>,
     * }
     */
    public function evaluate(array $window): array
    {
        $greenTicks = (int) ($window['green_ticks'] ?? 0);
        $recoveredCycles = (int) ($window['recovered_cycles'] ?? 0);
        $heldCycles = (int) ($window['held_cycles'] ?? 0);
        $forbiddenHits = (int) ($window['forbidden_dependency_hits'] ?? 0);
        $minGreen = (int) ($window['min_green_ticks'] ?? self::MIN_GREEN_TICKS);
        $minRecovered = (int) ($window['min_recovered_cycles'] ?? self::MIN_RECOVERED_CYCLES);
        $minHeld = (int) ($window['min_held_cycles'] ?? self::MIN_HELD_CYCLES);

        $reasons = [];

        if ($greenTicks < $minGreen) {
            $reasons[] = 'insufficient_green_ticks:' . $greenTicks . '<' . $minGreen;
        }

        if ($recoveredCycles < $minRecovered) {
            $reasons[] = 'insufficient_recovered_cycles:' . $recoveredCycles . '<' . $minRecovered;
        }

        if ($heldCycles < $minHeld) {
            $reasons[] = 'insufficient_held_cycles:' . $heldCycles . '<' . $minHeld;
        }

        if ($forbiddenHits > 0) {
            $reasons[] = 'forbidden_dependency_hits:' . $forbiddenHits;
        }

        if (count($reasons) > 0) {
            sort($reasons, SORT_STRING);

            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_PARTIAL,
                'reasons' => $reasons,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_GREEN,
            'reasons' => ['all_floors_met'],
        ];
    }
}
