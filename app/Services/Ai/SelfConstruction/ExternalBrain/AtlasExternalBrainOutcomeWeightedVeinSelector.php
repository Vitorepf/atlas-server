<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Selects the next leverage vein from recent outcome evidence instead of
 * static rotation. Successful veins are reinforced, failing veins cool down,
 * and neglected high-leverage veins receive priority.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainOutcomeWeightedVeinSelector
{
    public const SCHEMA = 'atlas.external_brain.outcome_weighted_vein_selector.v1';

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

    private const SUCCESS_WEIGHT = 1.0;
    private const FAILURE_WEIGHT = -2.0;
    private const NEGLECT_BONUS = 0.5;
    private const DECAY_PER_OUTCOME = 0.1;

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     * @param  array<string, mixed>  $veinStats  {vein_id: string, saturation: float, leverage_score: float, last_rotated_at: int}[]
     * @param  string|null  $currentVein
     * @return array<string, mixed>
     */
    public function select(array $outcomes, array $veinStats = [], ?string $currentVein = null): array
    {
        $statsMap = [];
        foreach ($veinStats as $stat) {
            if (! is_array($stat)) {
                continue;
            }
            $veinId = (string) ($stat['vein_id'] ?? '');
            if ($veinId === '') {
                continue;
            }
            $statsMap[$veinId] = [
                'saturation' => (float) ($stat['saturation'] ?? 0.0),
                'leverage_score' => (float) ($stat['leverage_score'] ?? 0.5),
                'last_rotated_at' => (int) ($stat['last_rotated_at'] ?? 0),
            ];
        }

        foreach (self::ALL_VEINS as $vein) {
            if (! isset($statsMap[$vein])) {
                $statsMap[$vein] = [
                    'saturation' => 0.0,
                    'leverage_score' => 0.5,
                    'last_rotated_at' => 0,
                ];
            }
        }

        $scores = [];
        foreach (self::ALL_VEINS as $vein) {
            $scores[$vein] = $statsMap[$vein]['leverage_score'];
        }

        $outcomeCounts = array_fill_keys(self::ALL_VEINS, 0);
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $vein = (string) ($outcome['vein_id'] ?? '');
            if (! in_array($vein, self::ALL_VEINS, true)) {
                continue;
            }

            $result = (string) ($outcome['result'] ?? '');
            $outcomeCounts[$vein]++;

            if (in_array($result, ['completed', 'success', 'delivered'], true)) {
                $scores[$vein] += self::SUCCESS_WEIGHT;
            } elseif (in_array($result, ['give_back', 'blocked', 'quarantined'], true)) {
                $scores[$vein] += self::FAILURE_WEIGHT;
            }
        }

        // Apply decay to veins that have not received recent outcomes.
        foreach (self::ALL_VEINS as $vein) {
            $scores[$vein] -= $outcomeCounts[$vein] * self::DECAY_PER_OUTCOME;
        }

        // Neglected high-leverage veins receive a priority bonus.
        $maxRotated = max(array_map(static fn (array $s): int => $s['last_rotated_at'], $statsMap)) ?: 1;
        foreach (self::ALL_VEINS as $vein) {
            $age = $maxRotated - $statsMap[$vein]['last_rotated_at'];
            if ($age > 0 && $statsMap[$vein]['leverage_score'] >= 0.7) {
                $scores[$vein] += self::NEGLECT_BONUS * $age;
            }
        }

        // Saturated veins are cooled down.
        foreach (self::ALL_VEINS as $vein) {
            if ($statsMap[$vein]['saturation'] >= 0.80) {
                $scores[$vein] -= 1.0;
            }
        }

        // Exclude current vein if alternatives exist.
        $candidates = self::ALL_VEINS;
        if ($currentVein !== null && count($candidates) > 1) {
            $candidates = array_values(array_filter($candidates, static fn (string $v): bool => $v !== $currentVein));
        }

        usort($candidates, static function (string $a, string $b) use ($scores, $statsMap): int {
            if ($scores[$a] !== $scores[$b]) {
                return $scores[$b] <=> $scores[$a];
            }
            $rotatedA = $statsMap[$a]['last_rotated_at'];
            $rotatedB = $statsMap[$b]['last_rotated_at'];
            return $rotatedA <=> $rotatedB;
        });

        $nextVein = $candidates[0] ?? self::VEIN_EXTERNAL_BRAIN;

        return [
            'schema_version' => self::SCHEMA,
            'next_vein' => $nextVein,
            'current_vein' => $currentVein,
            'changed' => $nextVein !== $currentVein,
            'vein_scores' => $scores,
            'outcome_counts' => $outcomeCounts,
            'selected_reason' => $this->reasonFor($nextVein, $scores, $statsMap),
        ];
    }

    /**
     * @param  array<string, float>  $scores
     * @param  array<string, array<string, mixed>>  $statsMap
     */
    private function reasonFor(string $vein, array $scores, array $statsMap): string
    {
        if ($statsMap[$vein]['saturation'] >= 0.80) {
            return 'saturated_cooldown';
        }

        $maxScore = max($scores);
        if ($scores[$vein] >= $maxScore) {
            return 'highest_weighted_score';
        }

        return 'fallback_rotation';
    }
}
