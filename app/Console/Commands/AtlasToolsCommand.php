<?php

namespace App\Console\Commands;

use App\Models\AtlasToolFinding;
use App\Services\Tools\AtlasToolApprovalService;
use App\Services\Tools\AtlasToolAuthorityMatrixService;
use App\Services\Tools\AtlasToolEvidenceQueryService;
use App\Services\Tools\AtlasToolExecutor;
use App\Services\Tools\AtlasToolFindingWaiverService;
use App\Services\Tools\AtlasToolGateService;
use App\Services\Tools\AtlasToolRegistryService;
use App\Services\Tools\AtlasToolReleaseGateService;
use Illuminate\Console\Command;

class AtlasToolsCommand extends Command
{
    protected $signature = 'atlas:tools
        {action=doctor : doctor, list, authority, status, commands, run, run-recipe, evidence, evidence-show, evidence-export, gate, release-gate, approve, revoke, waive-finding, revoke-finding-waiver or policies}
        {tool? : Tool slug for status/run, or run id for evidence-show/evidence-export}
        {--workspace= : Target workspace path. Defaults to current directory}
        {--command=* : Command argv for run. Pass one option per argv segment}
        {--recipe=version : Recipe name for run-recipe}
        {--tool-env=* : Safe env entry for run. Pass as KEY=VALUE; sensitive keys are rejected}
        {--output-limit=12000 : Max stdout/stderr bytes persisted per stream}
        {--dry-run : Register the planned run without executing}
        {--approved : Allow high-risk tools}
        {--required : Mark this tool as required evidence}
        {--max-execution-tier= : Highest execution tier allowed for this run: T0, T1, T2 or T3}
        {--sandbox-mode= : Policy sandbox context: workspace, worktree, docker, host or none}
        {--privacy-level= : Policy privacy context: standard, sensitive or restricted}
        {--task-type= : Policy task type context}
        {--requires-provider-safe : Require outputs to be safe for provider/model context}
        {--scope=workspace : Approval scope: workspace or global}
        {--reason= : Approval reason}
        {--ttl-hours=24 : Approval TTL in hours}
        {--network-allowed : Approval also permits tools that need network}
        {--finding-id= : Finding id for waiver actions}
        {--surface= : Filter evidence by surface}
        {--status= : Filter evidence by run status}
        {--policy-decision= : Filter evidence by policy decision}
        {--context-type= : Filter evidence by run context type}
        {--context-id= : Filter evidence by run context id}
        {--run-id= : Evidence run id for evidence-show/evidence-export}
        {--required-only : Filter evidence to required runs}
        {--required-tool=* : Required tool slug for gate action}
        {--fail-status=* : Status that blocks gate. Defaults to failed, timeout, requires_approval and denied}
        {--require-evidence : Gate blocks when no evidence matches filters}
        {--release-profile=security_sbom_release : Release gate profile}
        {--limit=20 : Evidence rows for evidence action}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and operate the Atlas Super Tool Runtime registry, policy and evidence store.';

    public function handle(
        AtlasToolRegistryService $registry,
        AtlasToolExecutor $executor,
        AtlasToolApprovalService $approvals,
        AtlasToolAuthorityMatrixService $authority,
        AtlasToolEvidenceQueryService $evidenceQuery,
        AtlasToolGateService $gate,
        AtlasToolFindingWaiverService $waivers,
        AtlasToolReleaseGateService $releaseGate,
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
                    'execution_tier' => $tool->execution_tier ?? data_get($tool->metadata, 'execution_tier', 'T1'),
                    'expected_cost' => $tool->expected_cost ?? data_get($tool->metadata, 'expected_cost', 'local_fast'),
                    'default_trigger' => $tool->default_trigger ?? data_get($tool->metadata, 'default_trigger', 'manual_or_policy'),
                    'authority_role' => $tool->authority_role ?? data_get($tool->metadata, 'authority_role', 'primary'),
                    'authority_group' => $tool->authority_group ?? data_get($tool->metadata, 'authority_group'),
                    'status' => $tool->status,
                    'capabilities' => $tool->capabilities_json,
                ])->values()->all(),
            ],
            'authority', 'matrix' => $authority->matrix(),
            'status' => $this->statusPayload($registry, $workspace),
            'commands' => $this->commandsPayload($registry, $workspace),
            'run' => $this->runPayload($executor, $workspace),
            'run-recipe', 'recipe' => $this->runRecipePayload($executor, $workspace),
            'evidence' => $this->evidencePayload($evidenceQuery, $workspace),
            'evidence-show' => $this->evidenceShowPayload($evidenceQuery),
            'evidence-export' => $this->evidenceExportPayload($evidenceQuery),
            'gate' => $this->gatePayload($gate, $workspace),
            'release-gate' => $this->releaseGatePayload($releaseGate, $workspace),
            'approve' => $this->approvePayload($approvals, $workspace),
            'revoke' => $this->revokePayload($approvals, $workspace),
            'waive-finding' => $this->waiveFindingPayload($waivers),
            'revoke-finding-waiver' => $this->revokeFindingWaiverPayload($waivers),
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
    private function commandsPayload(AtlasToolRegistryService $registry, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        if ($slug === '') {
            return ['status' => 'error', 'error' => 'tool_slug_required'];
        }

        return $registry->commandCatalog($slug, $workspace)
            ?? ['status' => 'missing', 'error' => 'tool_not_registered', 'tool' => $slug];
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

        try {
            $run = $executor->execute($slug, $workspace, $command, [
                'dry_run' => (bool) $this->option('dry-run'),
                'approved' => (bool) $this->option('approved'),
                'required' => (bool) $this->option('required'),
                'network_allowed' => (bool) $this->option('network-allowed'),
                'max_execution_tier' => $this->option('max-execution-tier'),
                'sandbox_mode' => $this->option('sandbox-mode'),
                'privacy_level' => $this->option('privacy-level'),
                'task_type' => $this->option('task-type'),
                'requires_provider_safe' => (bool) $this->option('requires-provider-safe') ?: null,
                'env' => (array) $this->option('tool-env'),
                'output_limit' => $this->option('output-limit'),
                'surface' => 'cli',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return ['status' => 'error', 'error' => 'invalid_tool_run_request', 'message' => $exception->getMessage()];
        } catch (\RuntimeException $exception) {
            return ['status' => 'error', 'error' => 'tool_runtime_unavailable', 'message' => $exception->getMessage()];
        }

        return ['run' => $run->load(['artifacts', 'findings'])->toArray()];
    }

    /**
     * @return array<string,mixed>
     */
    private function runRecipePayload(AtlasToolExecutor $executor, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        $recipe = trim((string) ($this->option('recipe') ?: 'version'));
        if ($slug === '' || $recipe === '') {
            return ['status' => 'error', 'error' => 'tool_and_recipe_required'];
        }

        try {
            $options = [
                'approved' => (bool) $this->option('approved'),
                'required' => (bool) $this->option('required'),
                'env' => (array) $this->option('tool-env'),
                'output_limit' => $this->option('output-limit'),
                'surface' => 'cli_recipe',
            ];
            if ($this->input->hasParameterOption('--dry-run')) {
                $options['dry_run'] = true;
            }

            $run = $executor->executeRecipe($slug, $recipe, $workspace, $options);
        } catch (\InvalidArgumentException $exception) {
            return ['status' => 'error', 'error' => 'invalid_tool_recipe_request', 'message' => $exception->getMessage()];
        } catch (\RuntimeException $exception) {
            return ['status' => 'error', 'error' => 'tool_runtime_unavailable', 'message' => $exception->getMessage()];
        }

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
    private function evidenceShowPayload(AtlasToolEvidenceQueryService $evidenceQuery): array
    {
        $runId = $this->evidenceRunId();
        if ($runId === '') {
            return ['status' => 'error', 'error' => 'run_id_required'];
        }

        $run = $evidenceQuery->findRun($runId, $this->evidenceWorkspaceFilter());

        return $run
            ? ['run' => $run->toArray()]
            : ['status' => 'missing', 'error' => 'tool_run_not_found', 'run_id' => $runId];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceExportPayload(AtlasToolEvidenceQueryService $evidenceQuery): array
    {
        $runId = $this->evidenceRunId();
        if ($runId === '') {
            return ['status' => 'error', 'error' => 'run_id_required'];
        }

        return $evidenceQuery->exportRun($runId, $this->evidenceWorkspaceFilter())
            ?? ['status' => 'missing', 'error' => 'tool_run_not_found', 'run_id' => $runId];
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
            'max_execution_tier' => $this->option('max-execution-tier'),
            'sandbox_mode' => $this->option('sandbox-mode'),
            'privacy_level' => $this->option('privacy-level'),
            'task_type' => $this->option('task-type'),
            'requires_provider_safe' => (bool) $this->option('requires-provider-safe'),
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
     * @return array<string,mixed>
     */
    private function waiveFindingPayload(AtlasToolFindingWaiverService $waivers): array
    {
        $finding = $this->findingForWaiverAction();
        if (! $finding) {
            return ['status' => 'missing', 'error' => 'tool_finding_not_found'];
        }

        $finding = $waivers->waive($finding, [
            'reason' => (string) ($this->option('reason') ?: 'operator_waived_tool_finding'),
            'ttl_hours' => $this->option('ttl-hours'),
            'waived_by' => 'atlas_cli',
            'source' => 'atlas_tools_cli',
        ]);

        return ['status' => 'waived', 'finding' => $finding->load('run')->toArray()];
    }

    /**
     * @return array<string,mixed>
     */
    private function revokeFindingWaiverPayload(AtlasToolFindingWaiverService $waivers): array
    {
        $finding = $this->findingForWaiverAction();
        if (! $finding) {
            return ['status' => 'missing', 'error' => 'tool_finding_not_found'];
        }

        $finding = $waivers->revoke($finding, [
            'reason' => (string) ($this->option('reason') ?: 'operator_revoked_tool_finding_waiver'),
            'revoked_by' => 'atlas_cli',
        ]);

        return ['status' => 'open', 'finding' => $finding->load('run')->toArray()];
    }

    /**
     * @return array<string,mixed>
     */
    private function gatePayload(AtlasToolGateService $gate, string $workspace): array
    {
        $slug = (string) $this->argument('tool');
        $requiredTools = (array) $this->option('required-tool');
        if ($slug !== '' && $requiredTools === []) {
            $requiredTools = [$slug];
        }

        return $gate->evaluate([
            'workspace' => $workspace,
            'tool_slug' => $slug !== '' ? $slug : null,
            'surface' => $this->option('surface'),
            'status' => $this->option('status'),
            'policy_decision' => $this->option('policy-decision'),
            'run_context_type' => $this->option('context-type'),
            'run_context_id' => $this->option('context-id'),
            'required' => (bool) $this->option('required-only') ?: null,
            'limit' => max(1, min(100, is_numeric($this->option('limit')) ? (int) $this->option('limit') : 20)),
        ], [
            'required_tools' => $requiredTools,
            'fail_statuses' => (array) $this->option('fail-status'),
            'require_evidence' => (bool) $this->option('require-evidence'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseGatePayload(AtlasToolReleaseGateService $releaseGate, string $workspace): array
    {
        return $releaseGate->evaluate([
            'workspace' => $workspace,
            'surface' => $this->option('surface') ?: 'engineering_quality_scan',
            'status' => $this->option('status'),
            'policy_decision' => $this->option('policy-decision'),
            'run_context_type' => $this->option('context-type'),
            'run_context_id' => $this->option('context-id'),
            'required' => (bool) $this->option('required-only') ?: null,
            'limit' => max(1, min(200, is_numeric($this->option('limit')) ? (int) $this->option('limit') : 100)),
        ], [
            'release_profile' => (string) ($this->option('release-profile') ?: 'security_sbom_release'),
            'fail_statuses' => (array) $this->option('fail-status'),
        ]);
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
            if (isset($payload['commands'])) {
                $tool = (array) $payload['tool'];
                $this->components->twoColumnDetail('Tool', (string) ($tool['slug'] ?? '-'));
                $this->table(['name', 'dry-run', 'tier', 'sandbox', 'command'], collect($payload['commands'])->map(fn (array $command): array => [
                    $command['name'] ?? '-',
                    (bool) ($command['dry_run_default'] ?? false) ? 'yes' : 'no',
                    $command['max_execution_tier'] ?? '-',
                    $command['sandbox_mode'] ?? '-',
                    implode(' ', (array) ($command['command'] ?? [])),
                ])->all());

                return;
            }

            $tool = (array) $payload['tool'];
            $this->table(['field', 'value'], collect($tool)->map(fn (mixed $value, string $key): array => [
                $key,
                is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            ])->all());

            return;
        }

        if (isset($payload['authority_groups'], $payload['summary'])) {
            $this->components->twoColumnDetail('Tools', (string) data_get($payload, 'summary.tool_count', 0));
            $this->components->twoColumnDetail('Authority groups', (string) data_get($payload, 'summary.authority_group_count', 0));
            $this->components->twoColumnDetail('Recommendations', (string) count((array) ($payload['recommendations'] ?? [])));
            $this->table(['group', 'primary', 'complementary', 'fallback', 'executors', 'tiers'], collect($payload['authority_groups'])->map(fn (array $group): array => [
                $group['authority_group'] ?? '-',
                collect($group['primary_tools'] ?? [])->pluck('slug')->join(', ') ?: '-',
                collect($group['complementary_tools'] ?? [])->pluck('slug')->join(', ') ?: '-',
                collect($group['fallback_tools'] ?? [])->pluck('slug')->join(', ') ?: '-',
                collect($group['executor_tools'] ?? [])->pluck('slug')->join(', ') ?: '-',
                implode(', ', (array) ($group['tier_span'] ?? [])) ?: '-',
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

        if (isset($payload['finding'])) {
            $finding = (array) $payload['finding'];
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? '-'));
            $this->components->twoColumnDetail('Finding', (string) ($finding['id'] ?? '-'));
            $this->components->twoColumnDetail('Finding status', (string) ($finding['status'] ?? '-'));
            $this->components->twoColumnDetail('Waiver', (string) ($finding['waiver_id'] ?? '-'));

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

        if (isset($payload['blocking_failures'], $payload['summary'])) {
            $summary = (array) $payload['summary'];
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? '-'));
            $this->components->twoColumnDetail('Runs', (string) ($summary['run_count'] ?? 0));
            $this->components->twoColumnDetail('Blocking failures', (string) ($summary['blocking_failure_count'] ?? 0));

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

    private function evidenceRunId(): string
    {
        $runId = (string) ($this->option('run-id') ?: $this->argument('tool') ?: '');

        return trim($runId);
    }

    private function findingForWaiverAction(): ?AtlasToolFinding
    {
        $findingId = trim((string) ($this->option('finding-id') ?: $this->argument('tool') ?: ''));
        if ($findingId === '') {
            return null;
        }

        return AtlasToolFinding::query()->with('run')->find($findingId);
    }

    /**
     * Run ids are globally unique. Scope by workspace only when the operator
     * explicitly provides --workspace; otherwise allow direct audit lookup.
     *
     * @return array<string,string>
     */
    private function evidenceWorkspaceFilter(): array
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return [];
        }

        $resolved = realpath($workspace);

        return ['workspace' => $resolved && is_dir($resolved) ? $resolved : $workspace];
    }
}
