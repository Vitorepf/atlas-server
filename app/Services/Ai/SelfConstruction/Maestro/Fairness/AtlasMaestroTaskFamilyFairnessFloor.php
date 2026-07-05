<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Fairness;

/**
 * Enforces a fairness floor across task families so muscles receive a balanced
 * mix of repair, learning, autonomy, simplification and verification work.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroTaskFamilyFairnessFloor
{
    public const SCHEMA = 'atlas.maestro.task_family_fairness_floor.v1';

    public const ACTION_PROMOTE = 'promote';
    public const ACTION_COOL_DOWN = 'cool_down';
    public const ACTION_HOLD = 'hold';

    private const FAMILIES = ['repair', 'learning', 'autonomy', 'simplification', 'verification'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $counts = is_array($input['family_counts'] ?? null) ? $input['family_counts'] : [];
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        $total = max(1, (int) array_sum($counts));
        $shares = [];
        foreach (self::FAMILIES as $family) {
            $shares[$family] = ((int) ($counts[$family] ?? 0)) / $total;
        }

        $averageShare = 1.0 / count(self::FAMILIES);
        $floor = $averageShare * 0.5;
        $ceiling = $averageShare * 2.0;

        $actions = [];
        foreach (self::FAMILIES as $family) {
            $share = $shares[$family];
            if ($share < $floor) {
                $actions[] = [
                    'family' => $family,
                    'action' => self::ACTION_PROMOTE,
                    'share' => round($share, 3),
                    'reason' => 'below_fairness_floor:'.round($share, 3),
                ];
            } elseif ($share > $ceiling) {
                $actions[] = [
                    'family' => $family,
                    'action' => self::ACTION_COOL_DOWN,
                    'share' => round($share, 3),
                    'reason' => 'above_fairness_ceiling:'.round($share, 3),
                ];
            } else {
                $actions[] = [
                    'family' => $family,
                    'action' => self::ACTION_HOLD,
                    'share' => round($share, 3),
                    'reason' => 'within_fairness_band',
                ];
            }
        }

        usort($actions, static function (array $a, array $b): int {
            $priority = [
                self::ACTION_PROMOTE => 0,
                self::ACTION_COOL_DOWN => 1,
                self::ACTION_HOLD => 2,
            ];

            return ($priority[$a['action']] ?? 99) <=> ($priority[$b['action']] ?? 99)
                ?: strcmp($a['family'], $b['family']);
        });

        $promoted = array_values(array_filter($actions, static fn (array $a): bool => $a['action'] === self::ACTION_PROMOTE));
        $cooled = array_values(array_filter($actions, static fn (array $a): bool => $a['action'] === self::ACTION_COOL_DOWN));

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'family_shares' => $shares,
            'actions' => $actions,
            'promoted_families' => array_column($promoted, 'family'),
            'cooled_families' => array_column($cooled, 'family'),
            'fairness_floor' => round($floor, 3),
            'fairness_ceiling' => round($ceiling, 3),
        ];
    }
}
