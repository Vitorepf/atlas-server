<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleDeadlockChecker;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCycleDeadlockChecker::check()} at the operator surface: reads a cycle finite
 * state machine from a JSON file and emits the model-check verdict — reachable deadlock states (non-terminal,
 * no outgoing) and livelock SCCs (≥2 states with no exit) — as deterministic facts.
 *
 * Read-only + pure: it model-checks the supplied FSM and reports; no provider/DB/mutation.
 */
final class AtlasLoopCycleDeadlockCommand extends Command
{
    protected $signature = 'atlas:loop:cycle-deadlock-check {--input=} {--json}';

    protected $description = 'Read-only model check of a cycle FSM for deadlock states and livelock SCCs.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('cycle-deadlock-check requires --input=<path to a readable FSM JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON object');
        }
        $fsm = isset($decoded['fsm']) && is_array($decoded['fsm']) ? $decoded['fsm'] : $decoded;

        $verdict = app(AtlasLoopCycleDeadlockChecker::class)->check($fsm);
        $verdict['deadlock_count'] = count($verdict['deadlocks']);
        $verdict['livelock_count'] = count($verdict['livelocks']);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('deadlocks: '.$verdict['deadlock_count'].'  livelocks: '.$verdict['livelock_count']);
            foreach ($verdict['deadlocks'] as $d) {
                $this->line('  deadlock @ '.$d['state']);
            }
        }

        return self::SUCCESS;
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
