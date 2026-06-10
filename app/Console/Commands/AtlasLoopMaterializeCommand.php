<?php

namespace App\Console\Commands;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalMaterializer;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Materialize a certified loop proposal's diff into an isolated workspace so the
 * win is inspectable + re-provable. Never merges to source — that flip stays the
 * operator's sovereignty decision.
 */
class AtlasLoopMaterializeCommand extends Command
{
    protected $signature = 'atlas:loop:materialize
        {proposal : Loop proposal id}
        {--base= : Base directory the diff applies against (default: repo root)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Materialize a certified loop proposal diff into an isolated workspace (never merged to source).';

    public function handle(AtlasLoopProposalMaterializer $materializer): int
    {
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            $this->warn('Table atlas_loop_proposals unavailable — nothing to materialize.');

            return self::SUCCESS;
        }

        $proposal = AtlasLoopProposal::query()->find($this->argument('proposal'));
        if ($proposal === null) {
            $this->error('Proposal not found: '.(string) $this->argument('proposal'));

            return self::FAILURE;
        }

        $base = (string) ($this->option('base') ?: base_path());
        $result = $materializer->materialize($proposal, $base);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['materialized'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['materialized']) {
            $this->info('Materialized to isolated workspace (never merged to source): '.(string) $result['isolated_path']);

            return self::SUCCESS;
        }

        $this->warn('Not materialized: '.(string) $result['reason']);

        return self::FAILURE;
    }
}
