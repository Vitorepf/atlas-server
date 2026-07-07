<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\AutonomousEvolution\Fanout\AtlasLoopFanOutProducer;
use Illuminate\Console\Command;

/**
 * ATLAS REDONDO SLICE 3 — the Loop-invocable surface for the fan-out producer.
 *
 * Takes a READY obra-decomposition envelope (the AtlasLoopObraDecompositionPlanner
 * shape) and composes the governed parallel worktree fan-out plan (N cells)
 * through the atlas_adapter-gated WorkcellAdapter. READ-ONLY: composes the plan
 * and launches nothing. Under workcell.policy=off (default) the composed plan is
 * dispatch_allowed_now=false; the actual spawn stays behind the existing
 * `atlas:hermes:workcell dispatch` gate (policy + --confirm).
 */
class AtlasLoopFanOutCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:loop:fanout
        {--goal= : the big obra goal/title}
        {--file= : JSON file with the ready decomposition envelope {ready:true, plan:{nodes:[...]}}}
        {--mode=write : permission mode for the cells (write|read)}
        {--json : Emit JSON}';

    protected $description = 'Loop fan-out producer: compose a governed parallel worktree fan-out plan from a ready obra-decomposition (gated by workcell.policy=atlas_adapter; read-only, launches nothing).';

    public function handle(AtlasLoopFanOutProducer $producer): int
    {
        $goal = trim((string) $this->option('goal'));
        if ($goal === '') {
            return $this->failWith('missing --goal=<obra goal>');
        }

        $file = $this->option('file');
        if (! is_string($file) || trim($file) === '' || ! is_file($file)) {
            return $this->failWith('missing/invalid --file=<decomposition json>');
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            return $this->failWith('file is not a valid JSON object');
        }

        $mode = (string) $this->option('mode') === 'read' ? 'read' : 'write';
        $result = $producer->planFanOut($goal, $decoded, $mode);

        if ((bool) $this->option('json')) {
            $this->jsonLine(['action' => 'fanout', 'fanout' => $result]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('fanned_out', ($result['fanned_out'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('cell_count', (string) ($result['cell_count'] ?? 0));
        $this->components->twoColumnDetail('policy_enabled', ($result['policy_enabled'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('dispatch allowed now', ($result['plan']['dispatch_allowed_now'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('reason', (string) ($result['reason'] ?? '—'));

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine(['action' => 'fanout', 'error' => $message]);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
