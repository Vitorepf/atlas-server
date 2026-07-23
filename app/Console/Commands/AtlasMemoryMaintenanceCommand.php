<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Memory\AtlasMemoryMaintenanceService;
use Illuminate\Console\Command;

class AtlasMemoryMaintenanceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:maintain
        {--workspace= : Workspace path used for code index and provider projections}
        {--dry-run : Preview sync/index steps without writing}
        {--no-sync : Skip canonical docs sync}
        {--no-index-code : Skip Code Intelligence indexing}
        {--no-prune : Do not archive stale docs/code records}
        {--no-promote-learnings : Skip accepted memory delta promotion}
        {--auto-promote-candidates : Promote trusted no-confirmation deltas above the confidence threshold}
        {--promotion-limit=50 : Maximum memory deltas to promote}
        {--promotion-min-confidence=0.86 : Minimum confidence for trusted candidate promotion}
        {--no-quality-snapshot : Do not record a persistent memory quality snapshot}
        {--enforce-quality : Fail maintenance when memory quality scorecard requires review}
        {--include-drift-audit : Include read-only Code Intelligence drift audit in MCP health}
        {--apply-projection : Apply provider projections when status is not passed}
        {--yes : Confirm provider projection apply}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas memory maintenance routine: docs sync, code index, provider projection status/apply and Open Brain health.';

    public function handle(AtlasMemoryMaintenanceService $maintenance): int
    {
        $payload = $maintenance->run([
            'workspace' => $this->option('workspace'),
            'dry_run' => (bool) $this->option('dry-run'),
            'sync' => ! (bool) $this->option('no-sync'),
            'index_code' => ! (bool) $this->option('no-index-code'),
            'prune' => ! (bool) $this->option('no-prune'),
            'promote_learnings' => ! (bool) $this->option('no-promote-learnings'),
            'auto_promote_candidates' => (bool) $this->option('auto-promote-candidates'),
            'promotion_limit' => (int) $this->option('promotion-limit'),
            'promotion_min_confidence' => (float) $this->option('promotion-min-confidence'),
            'record_quality_snapshot' => ! (bool) $this->option('no-quality-snapshot'),
            'enforce_quality' => (bool) $this->option('enforce-quality'),
            'include_drift_audit' => (bool) $this->option('include-drift-audit'),
            'apply_projection' => (bool) $this->option('apply-projection'),
            'confirm' => (bool) $this->option('yes'),
            'initiator' => 'cli',
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $payload['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return $this->renderHuman($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): int
    {
        $this->components->twoColumnDetail('Atlas Memory Maintenance', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace'] ?? '-'));
        $this->components->twoColumnDetail('Docs sync', $this->stageStatus(data_get($payload, 'stages.knowledge_sync')));
        $this->components->twoColumnDetail('Code index', $this->stageStatus(data_get($payload, 'stages.code_index')));
        $this->components->twoColumnDetail('Learning promotion', $this->stageStatus(data_get($payload, 'stages.learning_promotion')));
        $this->components->twoColumnDetail('Memory quality', (string) data_get($payload, 'stages.memory_quality.status', 'unknown').' / '.(string) data_get($payload, 'stages.memory_quality.score', '-'));
        $this->components->twoColumnDetail('Quality snapshot', $this->stageStatus(data_get($payload, 'stages.memory_quality_snapshot')));
        $this->components->twoColumnDetail('Projection', (string) data_get($payload, 'stages.provider_projection_status.status', 'unknown'));
        $this->components->twoColumnDetail('Health', (string) data_get($payload, 'stages.mcp_health.overall_status', 'unknown'));

        $actions = (array) data_get($payload, 'stages.mcp_health.next_actions', []);
        if ($actions !== []) {
            $this->newLine();
            $this->line('Proximas acoes:');
            foreach ($actions as $action) {
                $this->line('  - '.$action);
            }
        }

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function stageStatus(mixed $stage): string
    {
        if (! is_array($stage)) {
            return 'unknown';
        }

        if (($stage['status'] ?? null) === 'skipped') {
            return 'skipped';
        }

        return (bool) ($stage['ok'] ?? false) ? 'ok' : 'failed';
    }

}
