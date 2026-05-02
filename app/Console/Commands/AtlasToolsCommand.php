<?php

namespace App\Console\Commands;

use App\Services\Tools\AtlasToolApprovalService;
use App\Services\Tools\AtlasToolEvidenceQueryService;
use App\Services\Tools\AtlasToolExecutor;
use App\Services\Tools\AtlasToolRegistryService;
use Illuminate\Console\Command;

class AtlasToolsCommand extends Command
{
    protected $signature = 'atlas:tools
        {action=doctor : doctor, list, status, run, evidence, approve, revoke or policies}
        {tool? : Tool slug for status/run}
        {--workspace= : Target workspace path. Defaults to current directory}
        {--command=* : Command argv for run. Pass one option per argv segment}
        {--dry-run : Register the planned run without executing}
        {--approved : Allow high-risk tools}
        {--required : Mark this tool as required evidence}
        {--scope=workspace : Approval scope: workspace or global}
        {--reason= : Approval reason}
        {--ttl-hours=24 : Approval TTL in hours}
        {--network-allowed : Approval also permits tools that need network}
        {--surface= : Filter evidence by surface}
        {--status= : Filter evidence by run status}
        {--policy-decision= : Filter evidence by policy decision}
        {--context-type= : Filter evidence by run context type}
        {--context-id= : Filter evidence by run context id}
        {--required-only : Filter evidence to required runs}
        {--limit=20 : Evidence rows for evidence action}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and operate the Atlas Super Tool Runtime registry, policy and evidence store.';

    public function handle(
        AtlasToolRegistryService $registry,
        AtlasToolExecutor $executor,
        AtlasToolApprovalService $approvals,
        AtlasToolEvidenceQueryService $evidenceQuery,
    ): int {
        $action = strtolower((string) $this->argument('action'));
        $workspace = $this->workspace();

        $payload = match ($action) {
            'list' => [
                'tools' => $registry->definitions()->map(fn ($tool): array => [
                    'slug' => $tool->slug,
                    'name' => $tool->name,
                    'type' => $tool->type,
                    'category' => $tool->category,
                    'risk_level' => $tool->risk_level,
                    'cost_posture' => $tool->cost_posture,
                    'status' => $tool->status,
                    'capabilities' => $tool->capabilities_json,
                ])->values()->all(),
            ],
            'status' => $this->statusPayload($registry, $workspace),
            'run' => $this->runPayload($executor, $workspace),
            'evidence' => $this->evidencePayload($evidenceQuery, $workspace),
            'approve' => $this->approvePayload($approvals, $workspace),
            'revoke' => $this->revokePayload($approvals, $workspace),
            'policies' => $this->policiesPayload($approvals, $workspace),
            default => $registry->doctor($workspace),
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($action, $payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function statusPayload(AtlasToolRegistryService $registry, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        if ($slug === '') {
            return ['status' => 'error', 'error' => 'tool_slug_required'];
        }

        $definition = $registry->definition($slug);
        if (! $definition) {
            return ['status' => 'missing', 'error' => 'tool_not_registered', 'tool' => $slug];
        }

        return ['tool' => $registry->detect($definition, $workspace)];
    }

    /**
     * @return array<string,mixed>
     */
    private function runPayload(AtlasToolExecutor $executor, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        $command = array_values(array_filter((array) $this->option('command'), fn (mixed $part): bool => is_string($part) && $part !== ''));
        if ($slug === '' || $command === []) {
            return ['status' => 'error', 'error' => 'tool_and_command_required'];
        }

        $run = $executor->execute($slug, $workspace, $command, [
            'dry_run' => (bool) $this->option('dry-run'),
            'approved' => (bool) $this->option('approved'),
            'required' => (bool) $this->option('required'),
            'surface' => 'cli',
        ]);

        return ['run' => $run->load(['artifacts', 'findings'])->toArray()];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidencePayload(AtlasToolEvidenceQueryService $evidenceQuery, string $workspace): array
    {
        $limit = max(1, min(100, is_numeric($this->option('limit')) ? (int) $this->option('limit') : 20));
        $slug = (string) $this->argument('tool');

        return [
            'filters' => array_filter([
                'workspace' => $workspace,
                'tool_slug' => $slug !== '' ? $slug : null,
                'surface' => $this->option('surface'),
                'status' => $this->option('status'),
                'policy_decision' => $this->option('policy-decision'),
                'run_context_type' => $this->option('context-type'),
                'run_context_id' => $this->option('context-id'),
                'required' => (bool) $this->option('required-only') ?: null,
                'limit' => $limit,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'runs' => $evidenceQuery->recent([
                'workspace' => $workspace,
                'tool_slug' => $slug !== '' ? $slug : null,
                'surface' => $this->option('surface'),
                'status' => $this->option('status'),
                'policy_decision' => $this->option('policy-decision'),
                'run_context_type' => $this->option('context-type'),
                'run_context_id' => $this->option('context-id'),
                'required' => (bool) $this->option('required-only') ?: null,
                'limit' => $limit,
            ])->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function approvePayload(AtlasToolApprovalService $approvals, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        if ($slug === '') {
            return ['status' => 'error', 'error' => 'tool_slug_required'];
        }

        $policy = $approvals->approve($slug, $workspace, [
            'scope_type' => (string) $this->option('scope'),
            'reason' => (string) ($this->option('reason') ?: 'operator_approved_tool_execution'),
            'ttl_hours' => is_numeric($this->option('ttl-hours')) ? (int) $this->option('ttl-hours') : 24,
            'network_allowed' => (bool) $this->option('network-allowed'),
            'approved_by' => 'atlas_cli',
            'source' => 'atlas_tools_cli',
        ]);

        return ['status' => 'approved', 'policy' => $policy->toArray(), 'approval_status' => $approvals->approvalStatus($policy)];
    }

    /**
     * @return array<string,mixed>
     */
    private function revokePayload(AtlasToolApprovalService $approvals, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        if ($slug === '') {
            return ['status' => 'error', 'error' => 'tool_slug_required'];
        }

        $policy = $approvals->revoke($slug, $workspace, (string) $this->option('scope'));

        return [
            'status' => $policy ? 'revoked' : 'missing',
            'policy' => $policy?->toArray(),
            'approval_status' => $policy ? $approvals->approvalStatus($policy) : 'not_configured',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function policiesPayload(AtlasToolApprovalService $approvals, string $workspace): array
    {
        $limit = max(1, min(100, is_numeric($this->option('limit')) ? (int) $this->option('limit') : 20));

        return ['policies' => $approvals->policies($workspace, $limit)];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(string $action, array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Tools</>', $action);

        if (isset($payload['tools'])) {
            $this->table(['tool', 'status', 'layer', 'risk', 'version'], collect($payload['tools'])->map(fn (array $tool): array => [
                $tool['slug'] ?? '-',
                $tool['status'] ?? '-',
                $tool['execution_layer'] ?? '-',
                $tool['risk_level'] ?? '-',
                $tool['version'] ?? '-',
            ])->all());

            return;
        }

        if (isset($payload['tool'])) {
            $tool = (array) $payload['tool'];
            $this->table(['field', 'value'], collect($tool)->map(fn (mixed $value, string $key): array => [
                $key,
                is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            ])->all());

            return;
        }

        if (isset($payload['run'])) {
            $run = (array) $payload['run'];
            $this->components->twoColumnDetail('Run', (string) ($run['id'] ?? '-'));
            $this->components->twoColumnDetail('Status', (string) ($run['status'] ?? '-'));
            $this->components->twoColumnDetail('Policy', (string) ($run['policy_decision'] ?? '-'));

            return;
        }

        if (isset($payload['policy'])) {
            $policy = (array) $payload['policy'];
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? '-'));
            $this->components->twoColumnDetail('Approval', (string) ($payload['approval_status'] ?? '-'));
            $this->components->twoColumnDetail('Tool', (string) ($policy['tool_slug'] ?? '-'));
            $this->components->twoColumnDetail('Scope', (string) ($policy['scope_type'] ?? '-'));

            return;
        }

        if (isset($payload['policies'])) {
            $this->table(['tool', 'scope', 'enabled', 'approval', 'until'], collect($payload['policies'])->map(fn (array $policy): array => [
                $policy['tool_slug'] ?? '-',
                $policy['scope_type'] ?? '-',
                (bool) ($policy['enabled'] ?? false) ? 'yes' : 'no',
                $policy['approval_status'] ?? '-',
                data_get($policy, 'metadata.approved_until', '-'),
            ])->all());

            return;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
