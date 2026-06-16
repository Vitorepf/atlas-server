<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationProducer;
use Illuminate\Console\Command;

/**
 * ACDE O1 — author a PROPOSE-ONLY origination proposal for a target (spec-only, never executed, never merged).
 * Inert unless ATLAS_LOOP_ORIGINATION_PRODUCER_ENABLED=true. The producer drops forbidden self-targets and the
 * persisted row is retired (never merged) by the drain — this command can never push code to main.
 */
class AtlasLoopOriginateCommand extends Command
{
    protected $signature = 'atlas:loop:originate
        {target : the repo-relative target path to originate a proposal for}
        {--intent= : the human-readable origination intent}
        {--criteria=0 : the number of structural criteria the spec seeds (a hint, never the frozen bar)}
        {--json : canonical JSON output}';

    protected $description = 'Author a propose-only origination proposal (structure-only, never executed/merged). Default-OFF.';

    public function handle(AtlasLoopOriginationProducer $producer): int
    {
        $target = (string) $this->argument('target');
        $intent = trim((string) $this->option('intent')) !== '' ? (string) $this->option('intent') : ('Originate an improvement for '.$target);
        $id = $producer->produce($target, $intent, ['criteria_count' => max(0, (int) $this->option('criteria'))]);

        $payload = ['originated' => $id !== null, 'proposal_id' => $id, 'target' => $target];
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($id === null) {
            $this->warn('no origination proposal authored (producer is OFF, target is a forbidden self-target, or the target/intent was empty).');

            return self::SUCCESS;
        }
        $this->info('authored propose-only origination proposal '.$id.' for '.$target.' (spec-only — it will be reviewed, never auto-merged).');

        return self::SUCCESS;
    }
}
