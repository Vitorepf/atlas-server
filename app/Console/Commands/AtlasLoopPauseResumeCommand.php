<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseResumeSignalBridge;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCycleResumeFromCheckpoint;
use Illuminate\Console\Command;

/**
 * Operator CLI for the Loop cycle pause/resume sentinel. Thin shell over the pause-flag and
 * resume-checkpoint services. No provider calls, no source-control mutations — only the local
 * pause-sentinel file is touched.
 */
final class AtlasLoopPauseResumeCommand extends Command
{
    protected $signature = 'atlas:loop:pause-resume {action : pause|resume|status|clear} {--cycle=} {--phase=} {--reason=} {--json}';

    protected $description = 'Pause/resume/status/clear the Loop cycle sentinel (delegates to the pause flag and resume checkpoint services).';

    public const PAUSE_FLAG_BINDING = 'atlas.loop.pause_resume.pause_flag';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'pause' => $this->doPause(),
            'status' => $this->doStatus(),
            'resume' => $this->doResume(),
            'clear' => $this->doClear(),
            default => $this->emit(['action' => $action, 'outcome' => 'refused', 'reason' => 'unknown_action'], 1),
        };
    }

    private function doPause(): int
    {
        $cycle = (string) $this->option('cycle');
        $phase = (string) $this->option('phase');
        $reason = (string) $this->option('reason');
        if ($cycle === '' || $phase === '' || $reason === '') {
            return $this->emit(['action' => 'pause', 'outcome' => 'refused', 'reason' => 'missing_required_options'], 1);
        }
        $sentinel = $this->flag()->raise($cycle, $phase, $reason);
        // Surface the manual pause as a first-class cycle observability signal (master-gated no-op when OFF).
        $this->bridge()->onPauseRaised($cycle, $phase, $reason);

        return $this->emit(['action' => 'pause', 'outcome' => 'raised', 'sentinel' => $sentinel], 0);
    }

    private function doStatus(): int
    {
        $sentinel = $this->flag()->inspect();

        return $this->emit([
            'action' => 'status',
            'outcome' => $sentinel === null ? 'not_raised' : 'raised',
            'sentinel' => $sentinel,
        ], 0);
    }

    private function doResume(): int
    {
        $cycle = (string) $this->option('cycle');
        $phase = (string) $this->option('phase');
        if ($cycle === '' || $phase === '') {
            return $this->emit(['action' => 'resume', 'outcome' => 'refused', 'reason' => 'missing_required_options'], 1);
        }
        $svc = new AtlasLoopCycleResumeFromCheckpoint($this->flag());
        $checkpoint = [
            'cycle_id' => $cycle,
            'phase' => $phase,
            'facts' => [],
            'checkpoint_hash' => $svc->hashFacts([]),
        ];
        $result = $svc->resume($checkpoint);
        // Surface the resume outcome as a discrete signal: a clean resume vs an integrity-drift refusal.
        if (($result['outcome'] ?? '') === 'resumed') {
            $this->bridge()->onResumeAttempted($cycle, $result);
        } else {
            $this->bridge()->onResumeRefusedDueToDrift($cycle, $result);
        }

        return $this->emit(['action' => 'resume'] + $result, $result['outcome'] === 'resumed' ? 0 : 1);
    }

    private function doClear(): int
    {
        // Capture the sentinel's cycle_id BEFORE lowering so the signal can name the cycle it cleared.
        $existing = $this->flag()->inspect();
        $lowered = $this->flag()->lower();
        if ($lowered) {
            $cycle = $existing !== null ? (string) ($existing['cycle_id'] ?? '') : (string) $this->option('cycle');
            $this->bridge()->onPauseLowered($cycle);
        }

        return $this->emit([
            'action' => 'clear',
            'outcome' => $lowered ? 'cleared' : 'noop',
        ], 0);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        ksort($payload, SORT_STRING);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $exit;
    }

    private function bridge(): AtlasLoopCyclePauseResumeSignalBridge
    {
        $app = $this->getLaravel();
        if ($app->bound(AtlasLoopCyclePauseResumeSignalBridge::class)) {
            return $app->make(AtlasLoopCyclePauseResumeSignalBridge::class);
        }

        return new AtlasLoopCyclePauseResumeSignalBridge(new AtlasLoopCycleSignalEmitter, $this->flag());
    }

    private function flag(): AtlasLoopCyclePauseFlag
    {
        if ($this->getLaravel()->bound(self::PAUSE_FLAG_BINDING)) {
            $flag = $this->getLaravel()->make(self::PAUSE_FLAG_BINDING);
            if ($flag instanceof AtlasLoopCyclePauseFlag) {
                return $flag;
            }
        }

        return new AtlasLoopCyclePauseFlag(storage_path('atlas/loop/pause-sentinel.json'));
    }
}
