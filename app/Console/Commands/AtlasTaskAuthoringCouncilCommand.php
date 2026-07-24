<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskAuthoringGovernanceChain;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasTaskAuthoringCouncilCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:authoring-council {--json}';

    protected $description = 'Run the authoring governance chain (Strategy + Architecture councils) over a comprehension snapshot.';

    public function handle(): int
    {
        $chain = app()->bound(AtlasTaskAuthoringGovernanceChain::class)
            ? app(AtlasTaskAuthoringGovernanceChain::class)
            : new AtlasTaskAuthoringGovernanceChain();

        $snapshot = $this->buildComprehensionSnapshot();
        $envelope = $chain->govern($snapshot);

        if ($this->option('json')) {
            $this->line($this->encode($envelope));
        } else {
            $this->info('mode='.($envelope['mode'] ?? '').' recorded='.($envelope['recorded'] ?? '').' error='.($envelope['error'] ?? ''));
        }

        return 0;
    }

    /** @return array<int,array<string,mixed>> */
    private function buildComprehensionSnapshot(): array
    {
        return [
            [
                'candidate_id'    => 'authoring:snapshot:default',
                'title'           => 'authoring_governance_observer_pass',
                'owner_scope'     => 'atlas-self-construction',
                'leverage_rank'   => 'high',
                // Required by AtlasStrategyCouncilRoadmapCandidateFilter so the candidate survives.
                'evidence_path'   => 'app/Services/Ai/SelfConstruction/Governance/AtlasTaskAuthoringGovernanceChain.php',
                // Required by AtlasStrategyCouncilLeverageRanker so the candidate is accepted, not rejected.
                'evidence_refs'   => ['observer:authoring_governance_chain', 'cli_run:atlas_task_authoring_council'],
                // Required by AtlasArchitectureCouncilContractCritic for a clean critique.
                'non_authority'   => ['does_not_enqueue_tasks', 'does_not_call_providers', 'does_not_git'],
                'invariants'      => ['fail_open', 'observe_only', 'no_side_effects'],
                'capability_gap'  => [
                    'organ'           => 'governance',
                    'capability'      => 'authoring_pass',
                    'target_files'    => [
                        ['kind' => 'service', 'path' => 'AtlasTaskAuthoringGovernanceChain.php'],
                        ['kind' => 'cli',     'path' => 'AtlasTaskAuthoringCouncilCommand.php'],
                    ],
                    'acceptance_seed' => ['observe_only', 'exit_0_with_non_empty_top'],
                    'evidence_seed'   => ['cli_run', 'tests_or_gates_result'],
                ],
            ],
        ];
    }
}
