<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure synthesizer that produces compact heartbeat facts for autonomous loops
 * from health, drain, queue depth and recent outcome signals.
 *
 * The heartbeat includes:
 *   - liveness: whether the loop is alive
 *   - risk: current risk level
 *   - next_action: what the loop should do next
 *   - evidence_freshness: whether evidence is fresh or stale
 *
 * Provider-safe: never leaks raw prompts or traces.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizer
{
    public const SCHEMA = 'atlas.self_construction.autonomous_loop_heartbeat_synthesizer.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function synthesize(array $input): array
    {
        $healthStatus = (string) ($input['health_status'] ?? 'unknown');
        $queueDepth = (int) ($input['queue_depth'] ?? 0);
        $activeWorkers = (int) ($input['active_workers'] ?? 0);
        $serveRatePerMinute = (float) ($input['serve_rate_per_minute'] ?? 0.0);
        $recentSuccessCount = (int) ($input['recent_success_count'] ?? 0);
        $recentGiveBackCount = (int) ($input['recent_give_back_count'] ?? 0);
        $evidenceAgeSeconds = (int) ($input['evidence_age_seconds'] ?? 0);
        $riskLevel = (string) ($input['risk_level'] ?? 'low');
        $loopRunning = (bool) ($input['loop_running'] ?? false);

        $isAlive = $loopRunning && $healthStatus !== 'down';
        $isHealthy = $healthStatus === 'healthy';
        $isDry = $queueDepth === 0;
        $hasGiveBackPressure = $recentGiveBackCount > $recentSuccessCount && $recentGiveBackCount > 0;
        $evidenceFresh = $evidenceAgeSeconds <= 3600;

        $nextAction = match (true) {
            ! $isAlive => 'restart_loop',
            $isDry => 'originate',
            $hasGiveBackPressure => 'repair_first',
            $isHealthy => 'continue',
            default => 'monitor',
        };

        return [
            'schema_version' => self::SCHEMA,
            'liveness' => $isAlive ? 'alive' : 'dead',
            'health_status' => $healthStatus,
            'risk_level' => $riskLevel,
            'next_action' => $nextAction,
            'evidence_freshness' => $evidenceFresh ? 'fresh' : 'stale',
            'evidence_age_seconds' => $evidenceAgeSeconds,
            'queue_depth' => $queueDepth,
            'active_workers' => $activeWorkers,
            'serve_rate_per_minute' => $serveRatePerMinute,
            'recent_success_count' => $recentSuccessCount,
            'recent_give_back_count' => $recentGiveBackCount,
            'loop_running' => $loopRunning,
            'provider_safe' => true,
        ];
    }
}
