<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use Illuminate\Console\Command;

class AtlasAiSessionBootstrapCommand extends Command
{
    protected $signature = 'atlas:ai:session-bootstrap
        {--task= : Task, feature, bug or question this session will handle}
        {--workspace= : Workspace root used for provider projection status}
        {--strict : Return failure when the session placement gate is blocked}
        {--json : Print machine-readable JSON}';

    protected $description = 'Return the canonical Atlas AI session bootstrap package for a task.';

    public function handle(AtlasSessionBootstrapService $bootstrap, AtlasGovernanceGateService $gate): int
    {
        $payload = $bootstrap->bootstrap((string) ($this->option('task') ?: ''), [
            'workspace' => $this->option('workspace') ?: base_path(),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $gate->cliExitCode($payload, (bool) $this->option('strict'));
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Session Bootstrap</>', $payload['status']);
        $this->components->twoColumnDetail('Task', $payload['task']);
        $this->components->twoColumnDetail('Placement', data_get($payload, 'placement.layer').' / '.data_get($payload, 'placement.domain').' / '.data_get($payload, 'placement.flow'));
        $this->components->twoColumnDetail('Gate', (string) ($payload['gate_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Docs', data_get($payload, 'docs_health.status').' / '.data_get($payload, 'docs_health.required_missing_count').' missing');
        $this->components->twoColumnDetail('Docs split owner', data_get($payload, 'docs_split_plan.owner').' / '.data_get($payload, 'docs_split_plan.split_required_count').' docs');
        $this->components->twoColumnDetail('KB', data_get($payload, 'kb_status.status').' / '.data_get($payload, 'kb_status.active').' active');
        $this->components->twoColumnDetail('Provider projection', data_get($payload, 'provider_projection.status'));

        $safeNextBlocks = array_slice((array) ($payload['safe_next_blocks'] ?? []), 0, 5);
        if ($safeNextBlocks !== []) {
            $this->newLine();
            $this->line('Blocos seguros:');
            foreach ($safeNextBlocks as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block').' — '.data_get($block, 'dod_minimum'));
            }
        }

        $this->newLine();
        $this->line('Leia primeiro:');
        foreach ($payload['read_first'] as $doc) {
            $this->line('  - '.$doc);
        }

        $risks = (array) ($payload['risks'] ?? []);
        if ($risks !== []) {
            $this->newLine();
            $this->warn('Riscos: '.implode(', ', $risks));
        }

        $blocked = (array) ($payload['blocked_when'] ?? []);
        if ($blocked !== []) {
            $this->newLine();
            $this->warn('Bloqueios: '.implode(', ', $blocked));
        }

        return $gate->cliExitCode($payload, (bool) $this->option('strict'));
    }
}
