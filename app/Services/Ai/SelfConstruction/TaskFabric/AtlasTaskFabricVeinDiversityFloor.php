<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Enforces a vein diversity floor across ExternalBrain, SelfConstruction,
 * Task Fabric, Maestro, learning loop, autonomy and anti-Goodhart work
 * so originator batches do not camp on one fashionable organ.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskFabricVeinDiversityFloor
{
    public const SCHEMA = 'atlas.self_construction.task_fabric_vein_diversity_floor.v1';

    public const VEINS = [
        'external_brain', 'self_construction', 'task_fabric',
        'maestro', 'learning_loop', 'autonomy', 'anti_goodhart',
    ];

    public const MIN_DIVERSITY_FRACTION = 0.4;
    public const MAX_VEIN_FRACTION = 0.5;
    public const MIN_BATCH = 5;
    public const MAX_BATCH = 12;

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function evaluate(array $tasks): array
    {
        $count = count($tasks);
        $veinCounts = [];
        foreach (self::VEINS as $vein) {
            $veinCounts[$vein] = 0;
        }

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $vein = (string) ($task['vein'] ?? 'unknown');
            if (isset($veinCounts[$vein])) {
                $veinCounts[$vein]++;
            } else {
                $veinCounts[$vein] = 1;
            }
        }

        $representedVeins = array_filter($veinCounts, static fn (int $c): bool => $c > 0);
        $diversity = $count > 0 ? count($representedVeins) / count(self::VEINS) : 0.0;

        // Cap overrepresented veins
        $capped = [];
        $maxAllowed = (int) ceil($count * self::MAX_VEIN_FRACTION);
        foreach ($veinCounts as $vein => $c) {
            if ($c > $maxAllowed) {
                $capped[] = ['vein' => $vein, 'count' => $c, 'max_allowed' => $maxAllowed];
            }
        }

        // Request missing high-leverage veins
        $missing = array_values(array_filter(self::VEINS, static fn (string $v): bool => ($veinCounts[$v] ?? 0) === 0));

        $diverse = $diversity >= self::MIN_DIVERSITY_FRACTION && $capped === [];
        $batchValid = $count >= self::MIN_BATCH && $count <= self::MAX_BATCH;

        return [
            'schema' => self::SCHEMA,
            'diverse' => $diverse && $batchValid,
            'diversity_score' => round($diversity, 4),
            'min_diversity_fraction' => self::MIN_DIVERSITY_FRACTION,
            'represented_veins' => array_keys($representedVeins),
            'represented_vein_count' => count($representedVeins),
            'capped_veins' => $capped,
            'missing_veins' => $missing,
            'batch_valid' => $batchValid,
            'task_count' => $count,
        ];
    }
}
