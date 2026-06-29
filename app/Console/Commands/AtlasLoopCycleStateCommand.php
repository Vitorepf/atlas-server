<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeCycleStateMachine;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasAutonomousRuntimeCycleStateMachine::transitionTo()} at the operator
 * surface: validates an autonomous-runtime cycle state transition (--from -> --to, with optional --fact) and
 * emits whether it is accepted, the resulting state, and the rejection reason if any.
 *
 * Pure + read-only validation: the FSM starts at OBSERVE and is walked forward through the canonical cycle ring
 * to position it at --from (or SAFETY_STOP); nothing persistent is mutated.
 */
final class AtlasLoopCycleStateCommand extends Command
{
    protected $signature = 'atlas:loop:cycle-state {--from=} {--to=} {--fact=} {--json}';

    protected $description = 'Read-only autonomous-runtime cycle transition validator (--from -> --to).';

    public function handle(): int
    {
        $from = trim((string) $this->option('from')) ?: AtlasAutonomousRuntimeCycleStateMachine::OBSERVE;
        $to = trim((string) $this->option('to'));
        if ($to === '') {
            return $this->refuse('cycle-state requires --to=<state>');
        }

        $fact = [];
        $rawFact = trim((string) $this->option('fact'));
        if ($rawFact !== '') {
            $decoded = json_decode($rawFact, true);
            if (! is_array($decoded)) {
                return $this->refuse('--fact must be a JSON object');
            }
            $fact = $decoded;
        }

        $fsm = new AtlasAutonomousRuntimeCycleStateMachine();
        $positionError = $this->positionAt($fsm, $from);
        if ($positionError !== null) {
            return $this->refuse($positionError);
        }

        $verdict = $fsm->transitionTo($to, $fact);
        $facts = ['schema' => 'atlas.loop.cycle_state.v1', 'resulting_state' => $fsm->state()] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('accepted: '.($verdict['accepted'] ? 'yes' : 'no').'  '.$verdict['from'].' -> '.$verdict['to'].'  reason: '.($verdict['reason'] ?? '-'));
        }

        return self::SUCCESS;
    }

    /** Walk the FSM forward to $from; returns null on success or an error reason. */
    private function positionAt(AtlasAutonomousRuntimeCycleStateMachine $fsm, string $from): ?string
    {
        if ($from === $fsm->state()) {
            return null;
        }
        if ($from === AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP) {
            $fsm->transitionTo($from);

            return null;
        }
        $cycle = AtlasAutonomousRuntimeCycleStateMachine::CYCLE;
        if (! in_array($from, $cycle, true)) {
            return 'unknown_from_state:'.$from;
        }
        $guard = 0;
        while ($fsm->state() !== $from && $guard++ <= count($cycle) + 1) {
            $idx = array_search($fsm->state(), $cycle, true);
            if ($idx === false) {
                return 'cannot_position_from_state:'.$fsm->state();
            }
            $next = $cycle[($idx + 1) % count($cycle)];
            $step = $fsm->transitionTo($next);
            if (($step['accepted'] ?? false) !== true) {
                return 'position_walk_failed_at:'.$next;
            }
        }

        return $fsm->state() === $from ? null : 'could_not_reach_from_state:'.$from;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
