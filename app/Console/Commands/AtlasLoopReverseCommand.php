<?php

namespace App\Console\Commands;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalReverser;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;

/**
 * G5 — gera o artefato de ROLLBACK provado (round-trip) de um loop proposal
 * certificado. O patch reverso fica em storage/atlas/loop/reverse/ e o operador
 * o aplica com `git apply` se decidir desfazer um merge que ELE fez.
 */
class AtlasLoopReverseCommand extends Command
{
    protected $signature = 'atlas:loop:reverse
        {proposal : Loop proposal id}
        {--base= : Base directory the diff applies against (default: repo root)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Gera e PROVA (round-trip) o patch reverso de um loop proposal certificado — a alça de rollback do apply+reverse.';

    public function handle(AtlasLoopProposalReverser $reverser): int
    {
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            $this->warn('Table atlas_loop_proposals unavailable — nothing to reverse.');

            return self::SUCCESS;
        }

        $proposal = AtlasLoopProposal::query()->find($this->argument('proposal'));
        if ($proposal === null) {
            $this->error('Proposal not found: '.(string) $this->argument('proposal'));

            return self::FAILURE;
        }

        $result = $reverser->reverse($proposal, (string) ($this->option('base') ?: base_path()));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['reversed'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['reversed']) {
            $this->info('Reverse provado (round-trip) → '.(string) $result['reverse_patch_path']);
            $this->line('Rollback manual: '.(string) $result['applies_with']);

            return self::SUCCESS;
        }

        $this->warn('Reverse não gerado: '.(string) $result['reason']);

        return self::FAILURE;
    }
}
