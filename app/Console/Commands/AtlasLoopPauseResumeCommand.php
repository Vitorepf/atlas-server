<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
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

        return $this->emit(['action' => 'resume'] + $result, $result['outcome'] === 'resumed' ? 0 : 1);
    }

    private function doClear(): int
    {
        $lowered = $this->flag()->lower();

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
