<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Keeps the continuous originator from camping on sufficient_depth
 * by rotating among ExternalBrain, SelfConstruction, Task Fabric,
 * Maestro, learning loop, autonomy and anti-Goodhart veins when a
 * vein saturates.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainLeverageVeinRotator
{
    public const SCHEMA = 'atlas.self_construction.external_brain_leverage_vein_rotator.v1';

    public const VEIN_EXTERNAL_BRAIN = 'external_brain';
    public const VEIN_SELF_CONSTRUCTION = 'self_construction';
    public const VEIN_TASK_FABRIC = 'task_fabric';
    public const VEIN_MAESTRO = 'maestro';
    public const VEIN_LEARNING_LOOP = 'learning_loop';
    public const VEIN_AUTONOMY = 'autonomy';
    public const VEIN_ANTI_GOODHART = 'anti_goodhart';

    public const ALL_VEINS = [
        self::VEIN_EXTERNAL_BRAIN,
        self::VEIN_SELF_CONSTRUCTION,
        self::VEIN_TASK_FABRIC,
        self::VEIN_MAESTRO,
        self::VEIN_LEARNING_LOOP,
        self::VEIN_AUTONOMY,
        self::VEIN_ANTI_GOODHART,
    ];

    public const SATURATION_THRESHOLD = 0.80;

    /**
     * @param  array<string, mixed>  $veinStats  {vein_id: string, saturation: float, leverage_score: float, last_rotated_at: int}[]
     * @param  string|null  $currentVein
     * @return array<string, mixed>
     */
    public function rotate(array $veinStats, ?string $currentVein = null): array
    {
        // Build stats map
        $statsMap = [];
        foreach ($veinStats as $stat) {
            if (! is_array($stat)) {
                continue;
            }
            $veinId = (string) ($stat['vein_id'] ?? '');
            if ($veinId === '') {
                continue;
            }
            $statsMap[$veinId] = $stat;
        }

        // Ensure all veins are represented
        $allVeins = self::ALL_VEINS;
        foreach ($allVeins as $vein) {
            if (! isset($statsMap[$vein])) {
                $statsMap[$vein] = [
                    'vein_id' => $vein,
                    'saturation' => 0.0,
                    'leverage_score' => 0.5,
                    'last_rotated_at' => 0,
                ];
            }
        }

        // Sufficient depth never stops rotation — always pick a next vein
        // Filter out saturated veins (cool them down)
        $available = [];
        foreach ($allVeins as $vein) {
            $stat = $statsMap[$vein];
            $saturation = (float) ($stat['saturation'] ?? 0.0);
            if ($saturation < self::SATURATION_THRESHOLD) {
                $available[] = $vein;
            }
        }

        // If all are saturated, pick the least saturated
        if ($available === []) {
            usort($allVeins, static fn (string $a, string $b): int =>
                ($statsMap[$a]['saturation'] ?? 0.0) <=> ($statsMap[$b]['saturation'] ?? 0.0));
            $available = [$allVeins[0]];
        }

        // Exclude current vein if possible
        if ($currentVein !== null && count($available) > 1) {
            $available = array_values(array_filter($available, static fn (string $v): bool => $v !== $currentVein));
        }

        // Sort by leverage score descending, then by last_rotated_at ascending (oldest first)
        usort($available, function (string $a, string $b) use ($statsMap): int {
            $leverageA = (float) ($statsMap[$a]['leverage_score'] ?? 0.0);
            $leverageB = (float) ($statsMap[$b]['leverage_score'] ?? 0.0);
            if ($leverageA !== $leverageB) {
                return $leverageB <=> $leverageA; // higher leverage first
            }
            $rotatedA = (int) ($statsMap[$a]['last_rotated_at'] ?? 0);
            $rotatedB = (int) ($statsMap[$b]['last_rotated_at'] ?? 0);
            return $rotatedA <=> $rotatedB; // oldest first
        });

        $nextVein = $available[0] ?? self::VEIN_EXTERNAL_BRAIN;

        return [
            'schema' => self::SCHEMA,
            'next_vein' => $nextVein,
            'current_vein' => $currentVein,
            'rotated' => $nextVein !== $currentVein,
            'available_veins' => $available,
            'saturated_veins' => array_values(array_filter($allVeins, static fn (string $v): bool =>
                ($statsMap[$v]['saturation'] ?? 0.0) >= self::SATURATION_THRESHOLD)),
            'sufficient_depth_stops_rotation' => false,
        ];
    }
}
