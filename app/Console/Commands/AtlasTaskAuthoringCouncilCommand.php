<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskAuthoringGovernanceChain;
use Illuminate\Console\Command;

final class AtlasTaskAuthoringCouncilCommand extends Command
{
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
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
                'candidate_id' => 'authoring:snapshot:default',
                'title' => 'authoring_governance_observer_pass',
                'owner_scope' => 'atlas-self-construction',
                'leverage_rank' => 'high',
                'capability_gap' => [
                    'organ' => 'governance',
                    'capability' => 'authoring_pass',
                    'target_files' => [
                        ['kind' => 'service', 'path' => 'AtlasTaskAuthoringGovernanceChain.php'],
                    ],
                    'acceptance_seed' => ['observe_only'],
                    'evidence_seed' => ['cli_run'],
                ],
                'invariants' => ['fail_open', 'observe_only'],
            ],
        ];
    }
}
