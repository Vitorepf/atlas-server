<?php

namespace App\Services\Ai\Runtime;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolInstallation;
use App\Models\AtlasToolRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ToolActionRuntimeReadModel
{
    public const SCHEMA_VERSION = 'atlas.tool_action_runtime_report.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?string $workspace = null): array
    {
        $since ??= now()->subHours(24);
        $until ??= now();
        $workspace = $workspace !== null && trim($workspace) !== '' ? (realpath($workspace) ?: $workspace) : base_path();
        $workspaceHash = hash('sha256', $workspace);
        $tables = $this->tables();

        if (! $tables['atlas_tool_definitions'] || ! $tables['atlas_tool_runs']) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'workspace_hash' => $workspaceHash,
                'tables' => $tables,
                'status' => 'storage_unavailable',
                'writes' => false,
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'reasons' => ['tool_runtime_tables_missing'],
                    'recommended_action' => 'run_tool_runtime_migrations',
                ],
            ];
        }

        $definitions = AtlasToolDefinition::query()->get();
        $runs = AtlasToolRun::query()
            ->with('findings')
            ->whereBetween('created_at', [$since, $until])
            ->latest()
            ->get();
        $installations = $this->installations($workspaceHash, $tables['atlas_tool_installations']);
        $findings = $this->openFindings($runs, $tables['atlas_tool_findings']);
        $summary = $this->summary($definitions, $runs, $installations, $findings, $tables['atlas_tool_installations']);
        $reviewSignal = $this->reviewSignal($summary);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'workspace_hash' => $workspaceHash,
            'tables' => $tables,
            'status' => $reviewSignal['status'] === 'ok' ? 'ok' : 'warning',
            ...$summary,
            'review_signal' => $reviewSignal,
            'recent_runs' => $runs->take(10)->map(fn (AtlasToolRun $run): array => $this->runPayload($run))->values()->all(),
            'open_findings' => $findings->take(10)->map(fn (AtlasToolFinding $finding): array => $this->findingPayload($finding))->values()->all(),
            'writes' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function tables(): array
    {
        return [
            'atlas_tool_definitions' => Schema::hasTable('atlas_tool_definitions'),
            'atlas_tool_installations' => Schema::hasTable('atlas_tool_installations'),
            'atlas_tool_policies' => Schema::hasTable('atlas_tool_policies'),
            'atlas_tool_runs' => Schema::hasTable('atlas_tool_runs'),
            'atlas_tool_artifacts' => Schema::hasTable('atlas_tool_artifacts'),
            'atlas_tool_findings' => Schema::hasTable('atlas_tool_findings'),
        ];
    }

    /**
     * @return Collection<int,AtlasToolInstallation>
     */
    private function installations(string $workspaceHash, bool $tableExists): Collection
    {
        if (! $tableExists) {
            return collect();
        }

        return AtlasToolInstallation::query()
            ->where('workspace_hash', $workspaceHash)
            ->latest('detected_at')
            ->get()
            ->unique(fn (AtlasToolInstallation $installation): string => $installation->tool_definition_id.':'.$installation->execution_layer)
            ->values();
    }

    /**
     * @param  Collection<int,AtlasToolRun>  $runs
     * @return Collection<int,AtlasToolFinding>
     */
    private function openFindings(Collection $runs, bool $tableExists): Collection
    {
        if (! $tableExists || $runs->isEmpty()) {
            return collect();
        }

        return AtlasToolFinding::query()
            ->whereIn('tool_run_id', $runs->pluck('id')->all())
            ->where('status', 'open')
            ->latest()
            ->get();
    }

    /**
     * @param  Collection<int,AtlasToolDefinition>  $definitions
     * @param  Collection<int,AtlasToolRun>  $runs
     * @param  Collection<int,AtlasToolInstallation>  $installations
     * @param  Collection<int,AtlasToolFinding>  $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $definitions, Collection $runs, Collection $installations, Collection $findings, bool $installationTableExists): array
    {
        $latestRuns = $runs
            ->groupBy('tool_slug')
            ->map(fn (Collection $toolRuns): ?AtlasToolRun => $toolRuns
                ->sort(fn (AtlasToolRun $left, AtlasToolRun $right): int => [
                    $right->created_at?->getTimestamp() ?? 0,
                    $this->hasActionRuntimeContract($right) ? 1 : 0,
                    (string) $right->id,
                ] <=> [
                    $left->created_at?->getTimestamp() ?? 0,
                    $this->hasActionRuntimeContract($left) ? 1 : 0,
                    (string) $left->id,
                ])
                ->first())
            ->filter()
            ->values();
        $contractedRuns = $runs->filter(
            fn (AtlasToolRun $run): bool => $this->hasActionRuntimeContract($run)
        );
        $latestContractedRuns = $latestRuns->filter(
            fn (AtlasToolRun $run): bool => $this->hasActionRuntimeContract($run)
        );
        $unsafeContractRuns = $contractedRuns->filter(
            fn (AtlasToolRun $run): bool => $this->hasUnsafeActionRuntimeContract($run)
        );
        $latestUnsafeContractRuns = $latestContractedRuns->filter(
            fn (AtlasToolRun $run): bool => $this->hasUnsafeActionRuntimeContract($run)
        );
        $failedStatuses = ['failed', 'timeout', 'requires_approval', 'denied'];
        $blockingFindings = $findings->filter(fn (AtlasToolFinding $finding): bool => (bool) $finding->blocks_resolved);

        return [
            'definition_count' => $definitions->count(),
            'active_definition_count' => $definitions->where('status', 'active')->count(),
            'tool_category_counts' => $definitions->pluck('category')->filter()->countBy()->all(),
            'execution_tier_counts' => $definitions
                ->map(fn (AtlasToolDefinition $definition): string => (string) ($definition->execution_tier ?? data_get($definition->metadata, 'execution_tier', 'T1')))
                ->countBy()
                ->all(),
            'installation_tracking_available' => $installationTableExists,
            'installation_count' => $installations->count(),
            'ready_installation_count' => $installations->where('status', 'ready')->count(),
            'missing_installation_count' => $installations->where('status', 'missing')->count(),
            'evidence_run_count' => $runs->count(),
            'tool_evidence_count' => $runs->pluck('tool_slug')->unique()->count(),
            'required_evidence_run_count' => $runs->where('required', true)->count(),
            'failed_run_count' => $runs->whereIn('status', $failedStatuses)->count(),
            'failed_required_run_count' => $runs->where('required', true)->whereIn('status', $failedStatuses)->count(),
            'status_counts' => $runs->pluck('status')->filter()->countBy()->all(),
            'policy_decision_counts' => $runs->pluck('policy_decision')->filter()->countBy()->all(),
            'surface_counts' => $runs->pluck('surface')->filter()->countBy()->all(),
            'action_runtime_contract_count' => $contractedRuns->count(),
            'missing_action_runtime_contract_count' => $runs->count() - $contractedRuns->count(),
            'unsafe_action_runtime_contract_count' => $unsafeContractRuns->count(),
            'latest_evidence_run_count' => $latestRuns->count(),
            'latest_action_runtime_contract_count' => $latestContractedRuns->count(),
            'latest_missing_action_runtime_contract_count' => $latestRuns->count() - $latestContractedRuns->count(),
            'latest_unsafe_action_runtime_contract_count' => $latestUnsafeContractRuns->count(),
            'open_finding_count' => $findings->count(),
            'blocking_open_finding_count' => $blockingFindings->count(),
            'finding_severity_counts' => $findings->pluck('severity')->filter()->countBy()->all(),
        ];
    }

    private function hasActionRuntimeContract(AtlasToolRun $run): bool
    {
        return data_get($run->metadata_json, 'action_runtime_contract.schema_version') === 'atlas.tool_action_runtime.contract.v1';
    }

    private function hasUnsafeActionRuntimeContract(AtlasToolRun $run): bool
    {
        $contract = (array) data_get($run->metadata_json, 'action_runtime_contract', []);

        if ($contract === []) {
            return false;
        }

        return data_get($contract, 'raw_command_exposed') === true
            || data_get($contract, 'raw_output_exposed') === true
            || data_get($contract, 'workspace_path_exposed') === true
            || data_get($contract, 'provider_dispatch_allowed') === true
            || data_get($contract, 'runtime_policy_mutation_allowed') === true
            || data_get($contract, 'agent_control_plane_allowed') === true
            || data_get($contract, 'operator_approval_required_for_execution') === false;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary): array
    {
        if ((int) ($summary['failed_required_run_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['required_tool_evidence_failed'],
                'recommended_action' => 'inspect_failed_required_tool_runs',
            ];
        }

        if ((int) ($summary['blocking_open_finding_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['blocking_tool_findings_open'],
                'recommended_action' => 'resolve_or_waive_blocking_tool_findings',
            ];
        }

        if ((int) ($summary['latest_unsafe_action_runtime_contract_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['latest_tool_runs_have_unsafe_action_runtime_contract'],
                'recommended_action' => 'block_tool_promotion_until_action_runtime_contract_is_repaired',
            ];
        }

        if ((int) ($summary['evidence_run_count'] ?? 0) === 0) {
            return [
                'status' => 'unknown',
                'severity' => 'medium',
                'reasons' => ['no_tool_evidence_in_window'],
                'recommended_action' => 'record_tool_runtime_evidence_before_promotion',
            ];
        }

        if ((int) ($summary['latest_missing_action_runtime_contract_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['latest_tool_runs_missing_action_runtime_contract'],
                'recommended_action' => 'refresh_tool_evidence_with_action_runtime_contract',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reasons' => [],
            'recommended_action' => 'continue_tool_action_runtime_monitoring',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runPayload(AtlasToolRun $run): array
    {
        return [
            'id' => $run->id,
            'tool_slug' => $run->tool_slug,
            'surface' => $run->surface,
            'status' => $run->status,
            'required' => (bool) $run->required,
            'policy_decision' => $run->policy_decision,
            'action_runtime_contract' => data_get($run->metadata_json, 'action_runtime_contract.schema_version'),
            'action_runtime_contract_summary' => $this->actionRuntimeContractSummary($run),
            'created_at' => $run->created_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function actionRuntimeContractSummary(AtlasToolRun $run): array
    {
        $contract = (array) data_get($run->metadata_json, 'action_runtime_contract', []);

        return [
            'schema_version' => data_get($contract, 'schema_version'),
            'contract_hash' => $contract === [] ? null : hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'present' => $this->hasActionRuntimeContract($run),
            'unsafe' => $this->hasUnsafeActionRuntimeContract($run),
            'raw_command_exposed' => data_get($contract, 'raw_command_exposed') === true,
            'raw_output_exposed' => data_get($contract, 'raw_output_exposed') === true,
            'workspace_path_exposed' => data_get($contract, 'workspace_path_exposed') === true,
            'provider_dispatch_allowed' => data_get($contract, 'provider_dispatch_allowed') === true,
            'runtime_policy_mutation_allowed' => data_get($contract, 'runtime_policy_mutation_allowed') === true,
            'agent_control_plane_allowed' => data_get($contract, 'agent_control_plane_allowed') === true,
            'operator_approval_required_for_execution' => data_get($contract, 'operator_approval_required_for_execution') !== false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function findingPayload(AtlasToolFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'tool_run_id' => $finding->tool_run_id,
            'rule_id' => $finding->rule_id,
            'severity' => $finding->severity,
            'blocks_resolved' => (bool) $finding->blocks_resolved,
            'status' => $finding->status,
            'fingerprint' => $finding->fingerprint,
            'created_at' => $finding->created_at?->toJSON(),
        ];
    }
}
