<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleStateMachineExtractor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCycleStateMachineExtractor::extract()} at the operator surface: emits the
 * deterministic finite state machine of the canonical Loop cycle (states + guard-referenced transitions) as
 * a model-checkable artifact.
 *
 * Read-only and pure: it extracts the FSM and NEVER runs a cycle, mutates git, or touches the queue. The
 * payload is byte-identical across runs (fingerprinted).
 */
final class AtlasLoopCycleStateMachineCommand extends Command
{
    protected $signature = 'atlas:loop:cycle-state-machine {--json}';

    protected $description = 'Read-only extract of the canonical Loop cycle finite state machine (states + transitions).';

    public function handle(): int
    {
        $fsm = app(AtlasLoopCycleStateMachineExtractor::class)->extract();

        if ($this->option('json')) {
            $this->line((string) json_encode($fsm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('fingerprint: '.$fsm['fingerprint']);
            $this->line('states: '.implode(', ', $fsm['states']));
            foreach ($fsm['transitions'] as $t) {
                $this->line($t['from'].' -> '.$t['to'].'  ['.$t['guard'].']');
            }
        }

        return self::SUCCESS;
    }
}
