<?php

namespace App\Console\Commands;

use App\Models\AiLearningProposal;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Apply (or reverse) an APPROVED learning proposal to runtime behaviour — the last
 * wire of the compounding flywheel. Governed (approval-gated) and reversible.
 */
class AtlasApplyLearningCommand extends Command
{
    protected $signature = 'atlas:ai:apply-learning
        {proposal : Learning proposal id or hash}
        {--operator= : Operator id applying the change}
        {--reverse : Reverse a previously applied proposal}
        {--json : Print machine-readable JSON}';

    protected $description = 'Apply or reverse an approved learning proposal to runtime behaviour (closes the compounding flywheel).';

    public function handle(AtlasLearningProposalApplier $applier): int
    {
        if (! Schema::hasTable('ai_learning_proposals')) {
            $this->warn('Table ai_learning_proposals unavailable — nothing to apply.');

            return self::SUCCESS;
        }

        $id = (string) $this->argument('proposal');
        $proposal = AiLearningProposal::query()->where('id', $id)->orWhere('proposal_hash', $id)->first();
        if ($proposal === null) {
            $this->error('Learning proposal not found: '.$id);

            return self::FAILURE;
        }

        $operator = (string) $this->option('operator');
        $result = (bool) $this->option('reverse')
            ? $applier->reverse($proposal, $operator)
            : $applier->apply($proposal, $operator);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return (($result['applied'] ?? false) || ($result['reversed'] ?? false)) ? self::SUCCESS : self::FAILURE;
        }

        if ($result['applied'] ?? false) {
            $this->info('Applied learning ('.(string) $result['kind'].') — the flywheel turned; reversible via --reverse.');
            if (($result['persisted'] ?? true) === false) {
                $this->warn('Note: the route is live but the DB status save lagged (fail-safe). Re-run to reconcile bookkeeping.');
            }

            return self::SUCCESS;
        }
        if ($result['reversed'] ?? false) {
            $this->info('Reversed learning ('.(string) $result['kind'].').');
            if (($result['persisted'] ?? true) === false) {
                $this->warn('Note: the route was cleared but the DB status save lagged (fail-safe). Re-run to reconcile bookkeeping.');
            }

            return self::SUCCESS;
        }

        $this->warn('Not applied: '.(string) ($result['reason'] ?? 'unknown'));

        return self::FAILURE;
    }
}
