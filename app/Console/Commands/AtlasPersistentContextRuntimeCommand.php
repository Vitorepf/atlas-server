<?php

namespace App\Console\Commands;

use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use Illuminate\Console\Command;

class AtlasPersistentContextRuntimeCommand extends Command
{
    protected $signature = 'atlas:persistent-context
        {action=build : build|outcome}
        {--prompt= : Prompt/task to contextualize}
        {--workspace= : Workspace path}
        {--surface= : Surface id}
        {--domain= : Domain}
        {--flow= : Flow id}
        {--provider= : Provider}
        {--context-pack-id= : Existing persistent context pack id for outcome}
        {--summary= : Outcome summary for memory candidate}
        {--evidence=* : Evidence refs}
        {--json : Print JSON}';

    protected $description = 'Build and audit Atlas Persistent Context Runtime packs.';

    public function handle(AtlasPersistentContextRuntimeService $runtime): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'build' => $runtime->build([
                'prompt' => (string) ($this->option('prompt') ?: ''),
                'workspace' => (string) ($this->option('workspace') ?: base_path()),
                'surface_id' => $this->option('surface'),
                'domain' => $this->option('domain'),
                'flow_id' => $this->option('flow'),
                'provider' => $this->option('provider'),
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            'outcome' => $runtime->recordOutcome([
                'persistent_context_pack_id' => $this->option('context-pack-id'),
                'scope' => [
                    'scope_type' => 'workspace',
                    'scope_id' => 'manual',
                    'workspace' => (string) ($this->option('workspace') ?: base_path()),
                ],
                'evidence_refs' => (array) $this->option('evidence'),
            ], [
                'summary' => (string) ($this->option('summary') ?: 'Persistent context outcome recorded.'),
                'evidence_refs' => (array) $this->option('evidence'),
            ]),
            default => ['status' => 'error', 'error' => 'unknown_action'],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'blocked' ? 1 : 0;
        }

        $this->components->twoColumnDetail('APCR', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Context pack', (string) ($payload['context_pack_hash'] ?? '-'));
        $this->components->twoColumnDetail('Sufficiency', (string) data_get($payload, 'sufficiency.status', '-'));

        return ($payload['status'] ?? null) === 'blocked' ? 1 : 0;
    }
}
