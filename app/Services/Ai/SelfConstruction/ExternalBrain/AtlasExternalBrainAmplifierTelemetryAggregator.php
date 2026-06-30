<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure telemetry aggregator. Collapses shadow, canary, promotion, SLO,
 * scaffold compliance, and replay-court signals into a compact operational
 * view of model-amplifier health, without calling providers or mutating runtime.
 *
 * Status resolution (strongest blocking reason wins):
 *   rollback_candidate — any signal exceeds a hard failure threshold
 *   watch              — any signal is marginal (between warning and hard floor)
 *   healthy            — all signals above warning thresholds
 *
 * AC3: when signals disagree, the blocking_reasons list captures only
 *      the hardest-blocking signals; weak_signals captures the marginal ones.
 *
 * AC4: output always includes status, signal_rollup, blocking_reasons,
 *      weak_signals, and next_operator_free_action.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAmplifierTelemetryAggregator
{
    public const SCHEMA = 'atlas.external_brain.amplifier_telemetry_aggregator.v1';

    public const STATUS_HEALTHY            = 'healthy';
    public const STATUS_WATCH              = 'watch';
    public const STATUS_ROLLBACK_CANDIDATE = 'rollback_candidate';

    // Hard failure thresholds (→ rollback_candidate)
    private const SHADOW_FAILURE_FLOOR    = 0.50;
    private const CANARY_FAILURE_FLOOR    = 0.40;
    private const SLO_FAILURE_FLOOR       = 0.50;
    private const SCAFFOLD_FAILURE_FLOOR  = 0.50;
    private const REPLAY_FAILURE_FLOOR    = 0.50;

    // Warning thresholds (→ watch)
    private const SHADOW_WARNING_FLOOR    = 0.70;
    private const CANARY_WARNING_FLOOR    = 0.65;
    private const SLO_WARNING_FLOOR       = 0.70;
    private const SCAFFOLD_WARNING_FLOOR  = 0.70;
    private const REPLAY_WARNING_FLOOR    = 0.65;

    /**
     * @param  array{
     *   shadow_pass_rate?: float,
     *   canary_pass_rate?: float,
     *   slo_score?: float,
     *   slo_met?: bool,
     *   scaffold_compliance_rate?: float,
     *   replay_pass_rate?: float,
     *   promotion_ready?: bool,
     * }  $input
     * @return array{schema:string, status:string, signal_rollup:array<string,string>, blocking_reasons:list<string>, weak_signals:list<string>, next_operator_free_action:string}
     */
    public function aggregate(array $input): array
    {
        $shadow     = max(0.0, min(1.0, (float) ($input['shadow_pass_rate']        ?? 1.0)));
        $canary     = max(0.0, min(1.0, (float) ($input['canary_pass_rate']        ?? 1.0)));
        $sloScore   = max(0.0, min(1.0, (float) ($input['slo_score']               ?? 1.0)));
        $sloMet     = isset($input['slo_met']) ? (bool) $input['slo_met'] : ($sloScore >= self::SLO_WARNING_FLOOR);
        $scaffold   = max(0.0, min(1.0, (float) ($input['scaffold_compliance_rate'] ?? 1.0)));
        $replay     = max(0.0, min(1.0, (float) ($input['replay_pass_rate']         ?? 1.0)));

        $blocking = [];
        $weak     = [];
        $rollup   = [];

        // Shadow
        if ($shadow < self::SHADOW_FAILURE_FLOOR) {
            $blocking[] = "shadow_pass_rate:{$shadow}<".self::SHADOW_FAILURE_FLOOR;
            $rollup['shadow'] = 'blocking';
        } elseif ($shadow < self::SHADOW_WARNING_FLOOR) {
            $weak[]     = "shadow_pass_rate:{$shadow}<".self::SHADOW_WARNING_FLOOR;
            $rollup['shadow'] = 'watch';
        } else {
            $rollup['shadow'] = 'healthy';
        }

        // Canary
        if ($canary < self::CANARY_FAILURE_FLOOR) {
            $blocking[] = "canary_pass_rate:{$canary}<".self::CANARY_FAILURE_FLOOR;
            $rollup['canary'] = 'blocking';
        } elseif ($canary < self::CANARY_WARNING_FLOOR) {
            $weak[]     = "canary_pass_rate:{$canary}<".self::CANARY_WARNING_FLOOR;
            $rollup['canary'] = 'watch';
        } else {
            $rollup['canary'] = 'healthy';
        }

        // SLO
        if (! $sloMet && $sloScore < self::SLO_FAILURE_FLOOR) {
            $blocking[] = "slo_not_met:score={$sloScore}<".self::SLO_FAILURE_FLOOR;
            $rollup['slo'] = 'blocking';
        } elseif (! $sloMet || $sloScore < self::SLO_WARNING_FLOOR) {
            $weak[]     = "slo_marginal:score={$sloScore}";
            $rollup['slo'] = 'watch';
        } else {
            $rollup['slo'] = 'healthy';
        }

        // Scaffold compliance
        if ($scaffold < self::SCAFFOLD_FAILURE_FLOOR) {
            $blocking[] = "scaffold_compliance_rate:{$scaffold}<".self::SCAFFOLD_FAILURE_FLOOR;
            $rollup['scaffold'] = 'blocking';
        } elseif ($scaffold < self::SCAFFOLD_WARNING_FLOOR) {
            $weak[]     = "scaffold_compliance_rate:{$scaffold}<".self::SCAFFOLD_WARNING_FLOOR;
            $rollup['scaffold'] = 'watch';
        } else {
            $rollup['scaffold'] = 'healthy';
        }

        // Replay court
        if ($replay < self::REPLAY_FAILURE_FLOOR) {
            $blocking[] = "replay_pass_rate:{$replay}<".self::REPLAY_FAILURE_FLOOR;
            $rollup['replay'] = 'blocking';
        } elseif ($replay < self::REPLAY_WARNING_FLOOR) {
            $weak[]     = "replay_pass_rate:{$replay}<".self::REPLAY_WARNING_FLOOR;
            $rollup['replay'] = 'watch';
        } else {
            $rollup['replay'] = 'healthy';
        }

        $status = $this->resolveStatus($blocking, $weak);

        return [
            'schema'                    => self::SCHEMA,
            'status'                    => $status,
            'signal_rollup'             => $rollup,
            'blocking_reasons'          => $blocking,
            'weak_signals'              => $weak,
            'next_operator_free_action' => $this->nextAction($status),
        ];
    }

    private function resolveStatus(array $blocking, array $weak): string
    {
        if ($blocking !== []) {
            return self::STATUS_ROLLBACK_CANDIDATE;
        }

        if ($weak !== []) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_HEALTHY;
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            self::STATUS_ROLLBACK_CANDIDATE => 'trigger_rollback_and_disable_amplifier_promotion',
            self::STATUS_WATCH              => 'continue_monitoring_before_next_promotion_decision',
            default                         => 'promote_if_promotion_gate_passes',
        };
    }
}
