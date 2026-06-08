<?php

namespace App\Console\Commands;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalPromotionGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Govern-promote a certified loop proposal to a NEW BRANCH (never main). Requires
 * config `atlas.ai.loop.merge_to_source_enabled` + explicit operator approval; the
 * branch->main merge stays a human git/PR act.
 */
class AtlasLoopPromoteCommand extends Command
{
    protected $signature = 'atlas:loop:promote
        {proposal : Loop proposal id}
        {--operator= : Operator id approving the promotion}
        {--approve : Explicit approval (required; absence denies)}
        {--base= : Base directory the diff applies against (default: repo root)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Govern-promote a certified loop proposal to a branch (config + operator approval; never merges to main).';

    public function handle(AtlasLoopProposalPromotionGate $gate): int
    {
        if (! Schema::hasTable('atlas_loop_proposals')) {
            $this->warn('Table atlas_loop_proposals unavailable — nothing to promote.');

            return self::SUCCESS;
        }

        $proposal = AtlasLoopProposal::query()->find($this->argument('proposal'));
        if ($proposal === null) {
            $this->error('Proposal not found: '.(string) $this->argument('proposal'));

            return self::FAILURE;
        }

        $base = (string) ($this->option('base') ?: base_path());
        $result = $gate->promote($proposal, $base, [
            'operator_id' => (string) $this->option('operator'),
            'approved' => (bool) $this->option('approve'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['promoted'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['promoted']) {
            $this->info('Promoted to branch (never main): '.(string) $result['branch'].' @ '.(string) $result['isolated_path']);
            $this->line('Review + merge that branch into main yourself — Atlas never wrote main.');

            return self::SUCCESS;
        }

        $this->warn('Not promoted: '.(string) $result['reason']);

        return self::FAILURE;
    }
}
