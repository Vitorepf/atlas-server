<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\LaneProviderRoutingService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-804 · inspect the per-lane provider plan for the multi-agent Stewardship
 * cycle. Read-only: it never invokes a provider, never mutates anything.
 */
class AtlasAgentExecutionLaneProviderPlanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:agent-execution:lane-provider-plan
        {--topology-file= : Path to a JSON file with an Atlas Decide / injected provider topology}
        {--json : Emit JSON}';

    protected $description = 'AP-804 · inspect the provider plan routed per multi-agent lane (read-only, no provider call).';

    public function handle(LaneProviderRoutingService $routing): int
    {
        $topology = [];
        $file = (string) ($this->option('topology-file') ?? '');
        if ($file !== '') {
            if (! is_file($file)) {
                $this->error("topology-file not found: {$file}");

                return self::FAILURE;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                $this->error('topology-file is not valid JSON object');

                return self::FAILURE;
            }
            $topology = $decoded;
        }

        $lanes = [];
        foreach (['context_scout', 'architect', 'implementer', 'reviewer', 'repair_agent', 'judge'] as $i => $role) {
            $lanes[] = ['lane_id' => 'lane_'.($i + 1).'_'.$role, 'role' => $role];
        }

        $plan = $routing->route(['lanes' => $lanes, 'provider_topology' => $topology]);

        if ($this->option('json')) {
            $this->line($this->encode($plan));

            return self::SUCCESS;
        }

        $this->info('Lane provider plan ('.$plan['source'].', atlas_decide_used='.json_encode($plan['atlas_decide_used']).')');
        foreach ($plan['lanes'] as $lane) {
            $this->line(sprintf(
                '  %-14s -> %-12s model=%-22s auth=%-8s avail=%-11s %s',
                $lane['role'],
                (string) ($lane['selected_provider'] ?? 'none'),
                (string) ($lane['selected_model'] ?? $lane['selected_profile']),
                $lane['auth_mode'],
                $lane['availability'],
                $lane['blocker'] !== null ? 'BLOCKER='.$lane['blocker'] : ''
            ));
        }

        return self::SUCCESS;
    }
}
