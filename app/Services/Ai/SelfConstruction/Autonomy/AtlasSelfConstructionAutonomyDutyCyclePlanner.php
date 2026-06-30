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
}
