<?php

namespace App\Services\Ai\Runtime;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolInstallation;
use App\Models\AtlasToolRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        $runsQuery = AtlasToolRun::query()
            ->whereBetween('created_at', [$since, $until]);
        $installations = $this->installations($workspaceHash, $tables['atlas_tool_installations']);
        $runStats = $this->runStats($runsQuery);
        $findings = $this->openFindings($since, $until, $tables['atlas_tool_findings']);
        $findingStats = $this->findingStats($since, $until, $tables['atlas_tool_findings']);
        $summary = $this->summary($definitions, $runStats, $installations, $findingStats, $tables['atlas_tool_installations']);
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
            'recent_runs' => $this->recentRuns($since, $until)->map(fn (AtlasToolRun $run): array => $this->runPayload($run))->values()->all(),
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
     * @return Collection<int,AtlasToolFinding>
     */
    private function openFindings(CarbonInterface $since, CarbonInterface $until, bool $tableExists): Collection
    {
        if (! $tableExists) {
            return collect();
        }

        return AtlasToolFinding::query()
            ->whereHas('run', fn (Builder $query): Builder => $query->whereBetween('created_at', [$since, $until]))
            ->where('status', 'open')
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Collection $definitions, array $runStats, Collection $installations, array $findingStats, bool $installationTableExists): array
    {
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
            ...$runStats,
            ...$findingStats,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runStats(Builder $query): array
    {
        $failedStatuses = ['failed', 'timeout', 'requires_approval', 'denied'];
        $stats = [
            'evidence_run_count' => 0,
            'tool_evidence_count' => 0,
            'required_evidence_run_count' => 0,
            'failed_run_count' => 0,
            'failed_required_run_count' => 0,
            'latest_failed_required_run_count' => 0,
            'status_counts' => [],
            'policy_decision_counts' => [],
            'surface_counts' => [],
            'action_runtime_contract_count' => 0,
            'missing_action_runtime_contract_count' => 0,
            'unsafe_action_runtime_contract_count' => 0,
            'latest_evidence_run_count' => 0,
            'latest_action_runtime_contract_count' => 0,
            'latest_missing_action_runtime_contract_count' => 0,
            'latest_unsafe_action_runtime_contract_count' => 0,
        ];
        $toolSlugs = [];
        $latestByTool = [];

        (clone $query)
            ->select(['id', 'tool_slug', 'surface', 'status', 'required', 'policy_decision', 'metadata_json', 'created_at'])
            ->orderBy('created_at')
            ->chunk(500, function (Collection $runs) use (&$stats, &$toolSlugs, &$latestByTool, $failedStatuses): void {
                foreach ($runs as $run) {
                    /** @var AtlasToolRun $run */
                    $stats['evidence_run_count']++;
                    $toolSlug = (string) $run->tool_slug;
                    if ($toolSlug !== '') {
                        $toolSlugs[$toolSlug] = true;
                    }

                    $status = (string) $run->status;
                    $policyDecision = (string) $run->policy_decision;
                    $surface = (string) $run->surface;
                    $this->incrementCount($stats['status_counts'], $status);
                    $this->incrementCount($stats['policy_decision_counts'], $policyDecision);
                    $this->incrementCount($stats['surface_counts'], $surface);

                    $required = (bool) $run->required;
                    $failed = in_array($status, $failedStatuses, true);
                    $contracted = $this->hasActionRuntimeContract($run);
                    $unsafe = $contracted && $this->hasUnsafeActionRuntimeContract($run);

                    if ($required) {
                        $stats['required_evidence_run_count']++;
                    }
                    if ($failed) {
                        $stats['failed_run_count']++;
                    }
                    if ($required && $failed) {
                        $stats['failed_required_run_count']++;
                    }
                    if ($contracted) {
                        $stats['action_runtime_contract_count']++;
                    } else {
                        $stats['missing_action_runtime_contract_count']++;
                    }
                    if ($unsafe) {
                        $stats['unsafe_action_runtime_contract_count']++;
                    }
                    if ($toolSlug !== '' && $this->runWinsLatest($run, $latestByTool[$toolSlug] ?? null)) {
                        $latestByTool[$toolSlug] = $run;
                    }
                }
            });

        $stats['tool_evidence_count'] = count($toolSlugs);
        $stats['latest_evidence_run_count'] = count($latestByTool);
        foreach ($latestByTool as $run) {
            $status = (string) $run->status;
            $required = (bool) $run->required;
            $failed = in_array($status, $failedStatuses, true);
            $contracted = $this->hasActionRuntimeContract($run);
            $unsafe = $contracted && $this->hasUnsafeActionRuntimeContract($run);

            if ($required && $failed) {
                $stats['latest_failed_required_run_count']++;
            }
            if ($contracted) {
                $stats['latest_action_runtime_contract_count']++;
            } else {
                $stats['latest_missing_action_runtime_contract_count']++;
            }
            if ($unsafe) {
                $stats['latest_unsafe_action_runtime_contract_count']++;
            }
        }

        return $stats;
    }

    /**
     * @return Collection<int,AtlasToolRun>
     */
    private function recentRuns(CarbonInterface $since, CarbonInterface $until): Collection
    {
        return AtlasToolRun::query()
            ->whereBetween('created_at', [$since, $until])
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function findingStats(CarbonInterface $since, CarbonInterface $until, bool $tableExists): array
    {
        if (! $tableExists) {
            return [
                'open_finding_count' => 0,
                'blocking_open_finding_count' => 0,
                'finding_severity_counts' => [],
            ];
        }

        $base = AtlasToolFinding::query()
            ->whereHas('run', fn (Builder $query): Builder => $query->whereBetween('created_at', [$since, $until]))
            ->where('status', 'open');

        return [
            'open_finding_count' => (clone $base)->count(),
            'blocking_open_finding_count' => (clone $base)->where('blocks_resolved', true)->count(),
            'finding_severity_counts' => (clone $base)
                ->selectRaw('severity, count(*) as aggregate')
                ->whereNotNull('severity')
                ->groupBy('severity')
                ->pluck('aggregate', 'severity')
                ->all(),
        ];
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function incrementCount(array &$counts, string $key): void
    {
        if ($key === '') {
            return;
        }

        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function runWinsLatest(AtlasToolRun $candidate, ?AtlasToolRun $current): bool
    {
        if (! $current instanceof AtlasToolRun) {
            return true;
        }

        $candidateTimestamp = $candidate->created_at?->getTimestamp() ?? 0;
        $currentTimestamp = $current->created_at?->getTimestamp() ?? 0;
        if ($candidateTimestamp !== $currentTimestamp) {
            return $candidateTimestamp > $currentTimestamp;
        }

        $candidateContracted = $this->hasActionRuntimeContract($candidate);
        $currentContracted = $this->hasActionRuntimeContract($current);
        if ($candidateContracted !== $currentContracted) {
            return $candidateContracted;
        }

        return (string) $candidate->id > (string) $current->id;
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
        if ((int) ($summary['latest_failed_required_run_count'] ?? 0) > 0) {
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
