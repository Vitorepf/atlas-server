<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * 24/7 stop/go planner for the autonomous duty cycle.
 * Decides whether to originate work, heal the queue, or pause based on
 * queue depth, error rates, and congestion signals.
 *
 * DECISION PRIORITY (first match wins):
 *   1. self_heal_queue   — malformed_rate > malformed_threshold (default 0.30)
 *                          OR give_back_rate > give_back_threshold (default 0.30)
 *   2. drain_or_pause    — congestion_score > congestion_threshold (default 0.70)
 *   3. originate_or_expand — default steady-state
 *
 * ready_to_run: true for originate_or_expand and self_heal_queue; false for drain_or_pause.
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasSelfConstructionAutonomyDutyCyclePlanner
{
    public const SCHEMA = 'atlas.self_construction.autonomy.duty_cycle_planner.v1';

    public const ACTION_ORIGINATE_OR_EXPAND = 'originate_or_expand';
    public const ACTION_SELF_HEAL_QUEUE     = 'self_heal_queue';
    public const ACTION_DRAIN_OR_PAUSE      = 'drain_or_pause';

    private const DEFAULT_MALFORMED_THRESHOLD  = 0.30;
    private const DEFAULT_GIVE_BACK_THRESHOLD  = 0.30;
    private const DEFAULT_CONGESTION_THRESHOLD = 0.70;

    private const DEFAULT_DEBT_THRESHOLD = 0.60;

    private const DEFAULT_LOW_QUEUE_THRESHOLD = 2;

    /** @var array<string,float> baseline balanced allocation — every lane's weight sums to 1.0. */
    private const DUTY_CYCLE_HEALTHY = [
        'origination' => 0.30, 'execution' => 0.35, 'self_heal' => 0.10,
        'simplification' => 0.10, 'docs_sync' => 0.10, 'rest_window' => 0.05,
    ];

    /** @var array<string,float> overloaded — congestion demands the runtime rest, not push more work. */
    private const DUTY_CYCLE_OVERLOADED = [
        'origination' => 0.05, 'execution' => 0.10, 'self_heal' => 0.10,
        'simplification' => 0.05, 'docs_sync' => 0.05, 'rest_window' => 0.65,
    ];

    /** @var array<string,float> give_back-heavy — queue quality is broken, heal it before adding more. */
    private const DUTY_CYCLE_SELF_HEAL_HEAVY = [
        'origination' => 0.10, 'execution' => 0.15, 'self_heal' => 0.55,
        'simplification' => 0.05, 'docs_sync' => 0.05, 'rest_window' => 0.10,
    ];

    /** @var array<string,float> high simplification debt — collapse duplication before more raw output. */
    private const DUTY_CYCLE_SIMPLIFICATION_HEAVY = [
        'origination' => 0.15, 'execution' => 0.15, 'self_heal' => 0.10,
        'simplification' => 0.50, 'docs_sync' => 0.05, 'rest_window' => 0.05,
    ];

    /** @var array<string,float> starved queue — originate more material before anything else. */
    private const DUTY_CYCLE_ORIGINATION_HEAVY = [
        'origination' => 0.55, 'execution' => 0.15, 'self_heal' => 0.10,
        'simplification' => 0.05, 'docs_sync' => 0.10, 'rest_window' => 0.05,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $servableDepth    = (int)   ($input['servable_depth']        ?? 0);
        $malformedRate    = (float) ($input['malformed_rate']         ?? 0.0);
        $giveBackRate     = (float) ($input['give_back_rate']         ?? 0.0);
        $congestionScore  = (float) ($input['congestion_score']       ?? 0.0);
        $malformedThresh  = (float) ($input['malformed_threshold']    ?? self::DEFAULT_MALFORMED_THRESHOLD);
        $giveBackThresh   = (float) ($input['give_back_threshold']    ?? self::DEFAULT_GIVE_BACK_THRESHOLD);
        $congestionThresh = (float) ($input['congestion_threshold']   ?? self::DEFAULT_CONGESTION_THRESHOLD);

        [$action, $rationale] = match (true) {
            $malformedRate > $malformedThresh  => [
                self::ACTION_SELF_HEAL_QUEUE,
                sprintf('malformed_rate=%.2f exceeds threshold=%.2f', $malformedRate, $malformedThresh),
            ],
            $giveBackRate > $giveBackThresh    => [
                self::ACTION_SELF_HEAL_QUEUE,
                sprintf('give_back_rate=%.2f exceeds threshold=%.2f', $giveBackRate, $giveBackThresh),
            ],
            $congestionScore > $congestionThresh => [
                self::ACTION_DRAIN_OR_PAUSE,
                sprintf('congestion_score=%.2f exceeds threshold=%.2f', $congestionScore, $congestionThresh),
            ],
            default => [
                self::ACTION_ORIGINATE_OR_EXPAND,
                'queue healthy — low risk, proceed to originate or expand',
            ],
        };

        $readyToRun = $action !== self::ACTION_DRAIN_OR_PAUSE;

        return [
            'schema'             => self::SCHEMA,
            'recommended_action' => $action,
            'rationale'          => $rationale,
            'ready_to_run'       => $readyToRun,
            'servable_depth'     => $servableDepth,
        ];
    }

    /**
     * Long-running duty-cycle allocation across the six lanes a 24/7 autonomy schedule must
     * balance — never a single stop/go action. Each named condition (overload, self-heal need,
     * simplification debt, queue starvation) shifts the ENTIRE allocation toward the one lane that
     * matters most right now; the healthy default stays balanced across origination and execution.
     * Priority order (first match wins, matching plan()'s self-heal > pause precedence):
     *   1. congestion_score > congestion_threshold        → rest_window dominant
     *   2. give_back_rate > give_back_threshold           → self_heal dominant
     *   3. simplification_debt_score > debt_threshold     → simplification dominant
     *   4. servable_depth <= low_queue_threshold           → origination dominant
     *   5. default                                        → healthy balanced allocation
     *
     * @param  array<string,mixed>  $input
     * @return array{schema:string, duty_cycle:array<string,float>, quality_rationale:list<string>}
     */
    public function planDutyCycle(array $input): array
    {
        $servableDepth = (int) ($input['servable_depth'] ?? 0);
        $giveBackRate = (float) ($input['give_back_rate'] ?? 0.0);
        $congestionScore = (float) ($input['congestion_score'] ?? 0.0);
        $simplificationDebtScore = (float) ($input['simplification_debt_score'] ?? 0.0);

        $giveBackThresh = (float) ($input['give_back_threshold'] ?? self::DEFAULT_GIVE_BACK_THRESHOLD);
        $congestionThresh = (float) ($input['congestion_threshold'] ?? self::DEFAULT_CONGESTION_THRESHOLD);
        $debtThresh = (float) ($input['debt_threshold'] ?? self::DEFAULT_DEBT_THRESHOLD);
        $lowQueueThresh = (int) ($input['low_queue_threshold'] ?? self::DEFAULT_LOW_QUEUE_THRESHOLD);

        [$dutyCycle, $rationale] = match (true) {
            $congestionScore > $congestionThresh => [
                self::DUTY_CYCLE_OVERLOADED,
                sprintf('congestion_score=%.2f exceeds threshold=%.2f — shifting duty cycle toward rest_window', $congestionScore, $congestionThresh),
            ],
            $giveBackRate > $giveBackThresh => [
                self::DUTY_CYCLE_SELF_HEAL_HEAVY,
                sprintf('give_back_rate=%.2f exceeds threshold=%.2f — shifting duty cycle toward self_heal', $giveBackRate, $giveBackThresh),
            ],
            $simplificationDebtScore > $debtThresh => [
                self::DUTY_CYCLE_SIMPLIFICATION_HEAVY,
                sprintf('simplification_debt_score=%.2f exceeds threshold=%.2f — shifting duty cycle toward simplification', $simplificationDebtScore, $debtThresh),
            ],
            $servableDepth <= $lowQueueThresh => [
                self::DUTY_CYCLE_ORIGINATION_HEAVY,
                sprintf('servable_depth=%d at or below threshold=%d — shifting duty cycle toward origination', $servableDepth, $lowQueueThresh),
            ],
            default => [
                self::DUTY_CYCLE_HEALTHY,
                'all signals within healthy range — balanced allocation across origination and execution',
            ],
        };

        return [
            'schema' => self::SCHEMA,
            'duty_cycle' => $dutyCycle,
            'quality_rationale' => [$rationale],
        ];
    }
}
