<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PauseResume;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter;

/**
 * Wires the W1460 pause/resume primitives into the Atlas cycle observability surface so
 * operator-visible cycle telemetry sees pauses/resumes as discrete first-class signals.
 *
 * FACT-only: every signal payload carries discrete event facts (cycle_id, phase, sentinel
 * sha256, timestamps). NEVER an aggregated score/rank/health/composite/total scalar — a
 * Goodhart guard inside this class refuses to emit any such key.
 *
 * Master-OFF byte-identical: when ATLAS_LOOP_MASTER_ENABLED is false (the operator's global
 * pause), ALL onX methods short-circuit before touching the emitter or the filesystem.
 */
final class AtlasLoopCyclePauseResumeSignalBridge
{
    public const STAGE_PAUSED = 'cycle.paused';

    public const STAGE_PAUSE_LOWERED = 'cycle.pause_lowered';

    public const STAGE_RESUMED = 'cycle.resumed';

    public const STAGE_RESUME_REFUSED = 'cycle.resume_refused';

    public const CAMPAIGN_ID = 'atlas.loop.cycle.pause_resume';

    /**
     * @param  callable():bool|null  $masterGate  optional override (defaults to AtlasLoopMasterSwitch::enabled).
     */
    public function __construct(
        private readonly AtlasLoopCycleSignalEmitter $emitter,
        private readonly AtlasLoopCyclePauseFlag $pauseFlag,
        private $masterGate = null,
    ) {}

    public function onPauseRaised(string $cycleId, string $phase, string $reason): void
    {
        if (! $this->armed()) {
            return;
        }
        $sentinel = $this->pauseFlag->inspect();
        if ($sentinel === null) {
            return;
        }
        $this->emit(self::STAGE_PAUSED, $cycleId, [
            'cycle_id' => $cycleId,
            'phase_at_pause' => $phase,
            'reason' => $reason,
            'sentinel_sha256' => $this->sentinelSha256($sentinel),
            'paused_at' => (string) $sentinel['raised_at'],
        ]);
    }

    public function onPauseLowered(string $cycleId): void
    {
        if (! $this->armed()) {
            return;
        }
        $this->emit(self::STAGE_PAUSE_LOWERED, $cycleId, [
            'cycle_id' => $cycleId,
            'lowered_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $outcome  result envelope from AtlasLoopCycleResumeFromCheckpoint::resume
     */
    public function onResumeAttempted(string $cycleId, array $outcome): void
    {
        if (! $this->armed()) {
            return;
        }
        $this->emit(self::STAGE_RESUMED, $cycleId, [
            'cycle_id' => $cycleId,
            'resumed_phase' => isset($outcome['resumed_phase']) ? (string) $outcome['resumed_phase'] : '',
            'integrity_ok' => (string) ($outcome['outcome'] ?? '') === 'resumed',
            'resumed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $outcome  refused-result envelope (must carry reason and the paused phase).
     */
    public function onResumeRefusedDueToDrift(string $cycleId, array $outcome): void
    {
        if (! $this->armed()) {
            return;
        }
        $reason = (string) ($outcome['reason'] ?? 'integrity_drift');
        $pausedPhase = '';
        $sentinel = $this->pauseFlag->inspect();
        if ($sentinel !== null) {
            $pausedPhase = (string) ($sentinel['phase'] ?? '');
        }
        $this->emit(self::STAGE_RESUME_REFUSED, $cycleId, [
            'cycle_id' => $cycleId,
            'reason' => $reason,
            'refused_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'paused_phase_recovered' => $pausedPhase,
        ]);
    }

    /**
     * @param  array<string,mixed>  $sentinel
     */
    private function sentinelSha256(array $sentinel): string
    {
        ksort($sentinel, SORT_STRING);

        return hash('sha256', (string) json_encode($sentinel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(string $stage, string $cycleId, array $payload): void
    {
        $payload = $this->goodhartGuard($payload);
        $this->emitter->emit($stage, self::CAMPAIGN_ID, $cycleId, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function goodhartGuard(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (preg_match('/^(score|rank|health|composite|total)$/i', (string) $key) === 1) {
                throw new \RuntimeException('signal_payload_carries_aggregate_scalar:'.$key);
            }
        }

        return $payload;
    }

    private function armed(): bool
    {
        if (is_callable($this->masterGate)) {
            return (bool) call_user_func($this->masterGate);
        }

        return AtlasLoopMasterSwitch::enabled();
    }
}
