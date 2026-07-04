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

    /**
     * Replay individual candidates through the value gate and produce
     * would_admit, false_reject_green, true_reject_poison counts with
     * threshold adjustment guidance.
     *
     * @param  list<array{task_packet_id:string, outcome:string, value_density:float, gate_passed:bool}>  $candidates
     * @param  float  $currentThreshold
     * @return array{schema:string, would_admit:int, false_reject_green:int, true_reject_poison:int, total:int, threshold_adjustment:?array{direction:string, reason:string, suggested_threshold:float}}
     */
    public function replayCandidates(array $candidates, float $currentThreshold = 0.5): array
    {
        $wouldAdmit = 0;
        $falseRejectGreen = 0;
        $trueRejectPoison = 0;

        foreach ($candidates as $c) {
            $passed = (bool) ($c['gate_passed'] ?? false);
            $outcome = (string) ($c['outcome'] ?? '');
            $density = (float) ($c['value_density'] ?? 0);

            // Gate-passing candidates increment would_admit
            if ($passed) {
                $wouldAdmit++;
            }

            // Rejected successful tasks increment false_reject_green
            if (! $passed && in_array($outcome, ['success', 'resolved', 'green'], true)) {
                $falseRejectGreen++;
            }

            // Give_back or poison tasks rejected by the gate increment true_reject_poison
            if (! $passed && in_array($outcome, ['give_back', 'poison', 'rejected', 'failed'], true)) {
                $trueRejectPoison++;
            }
        }

        $total = count($candidates);
        $adjustment = null;

        if ($total > 0) {
            $poisonRate = $trueRejectPoison / $total;
            $greenMissRate = $falseRejectGreen / $total;

            if ($poisonRate > 0.1) {
                $adjustment = [
                    'direction' => 'tighten',
                    'reason' => "high_poison_rate:{$poisonRate} of rejected candidates were poison — gate too loose",
                    'suggested_threshold' => round(min(1.0, $currentThreshold + 0.1), 4),
                ];
            } elseif ($greenMissRate > 0.1) {
                $adjustment = [
                    'direction' => 'loosen',
                    'reason' => "high_green_miss_rate:{$greenMissRate} of rejected candidates were green — gate too tight",
                    'suggested_threshold' => round(max(0.0, $currentThreshold - 0.1), 4),
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'would_admit' => $wouldAdmit,
            'false_reject_green' => $falseRejectGreen,
            'true_reject_poison' => $trueRejectPoison,
            'total' => $total,
            'threshold_adjustment' => $adjustment,
        ];
    }
}
