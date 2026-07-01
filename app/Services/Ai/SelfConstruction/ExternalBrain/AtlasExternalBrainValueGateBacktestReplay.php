<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure backtest replay that makes value-gate threshold adjustments auditable.
 *
 * Every recommended adjustment includes replay_reason, prevented_failure_modes,
 * and false_negative_risk — never a bare numeric tweak.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainValueGateBacktestReplay
{
    public const SCHEMA = 'atlas.external_brain.value_gate_backtest_replay.v1';

    public const ACTION_TIGHTEN = 'tighten_risk_ceiling';
    public const ACTION_LOOSEN = 'loosen_impact_floor';
    public const ACTION_NONE = 'no_adjustment';

    /**
     * @param  array{
     *   admitted_poison_count?:int,
     *   rejected_green_count?:int,
     *   total_admitted?:int,
     *   total_rejected?:int,
     *   poison_examples?:list<string>,
     *   green_examples?:list<string>,
     * }  $backtest
     * @return array{
     *   schema:string,
     *   action:string,
     *   adjustment:?array{
     *     replay_reason:string,
     *     prevented_failure_modes:list<string>,
     *     false_negative_risk:float,
     *   },
     *   audit_reason:string,
     * }
     */
    public function replay(array $backtest): array
    {
        $admittedPoison = (int) ($backtest['admitted_poison_count'] ?? 0);
        $rejectedGreen = (int) ($backtest['rejected_green_count'] ?? 0);
        $poisonExamples = (array) ($backtest['poison_examples'] ?? []);
        $greenExamples = (array) ($backtest['green_examples'] ?? []);

        // Tighten risk ceiling when poison was admitted
        if ($admittedPoison > 0) {
            return [
                'schema' => self::SCHEMA,
                'action' => self::ACTION_TIGHTEN,
                'adjustment' => [
                    'replay_reason' => "admitted_poison:{$admittedPoison} tasks passed gate but were poison",
                    'prevented_failure_modes' => array_slice($poisonExamples, 0, 5),
                    'false_negative_risk' => 0.0,
                ],
                'audit_reason' => "tighten:{$admittedPoison} poison admitted requires ceiling reduction",
            ];
        }

        // Loosen impact floor when green tasks were rejected
        if ($rejectedGreen > 0) {
            return [
                'schema' => self::SCHEMA,
                'action' => self::ACTION_LOOSEN,
                'adjustment' => [
                    'replay_reason' => "rejected_green:{$rejectedGreen} tasks rejected by gate but were high-value",
                    'prevented_failure_modes' => [],
                    'false_negative_risk' => round($rejectedGreen / max(1, $rejectedGreen + (int) ($backtest['total_admitted'] ?? 0)), 4),
                ],
                'audit_reason' => "loosen:{$rejectedGreen} green tasks rejected requires floor reduction",
            ];
        }

        // No adjustment needed
        return [
            'schema' => self::SCHEMA,
            'action' => self::ACTION_NONE,
            'adjustment' => null,
            'audit_reason' => 'no_adjustment: backtest shows clean gate calibration',
        ];
    }
}
