<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Support\AtlasSecurity;
use Illuminate\Database\Eloquent\Collection;

class AtlasToolEvidenceQueryService
{
    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasToolRun>
     */
    public function recent(array $filters = []): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_tool_runs')) {
            return new Collection;
        }

        $query = AtlasToolRun::query()
            ->with(['tool', 'artifacts', 'findings'])
            ->latest('created_at')
            ->latest('id');

        $workspaceHash = $this->workspaceHash($filters['workspace'] ?? null);
        if ($workspaceHash !== null) {
            $query->where('workspace_hash', $workspaceHash);
        }

        foreach (['tool_slug', 'surface', 'status', 'policy_decision', 'run_context_type', 'run_context_id'] as $field) {
            $values = AiStringListNormalizer::uniqueCommaSeparatedStrings($filters[$field] ?? null);
            if (count($values) === 1) {
                $query->where($field, $values[0]);
            } elseif ($values !== []) {
                $query->whereIn($field, $values);
            }
        }

        foreach (['recipe', 'recipe_category', 'recipe_recommended_surface'] as $field) {
            $values = AiStringListNormalizer::uniqueCommaSeparatedStrings($filters[$field] ?? null);
            if (count($values) === 1) {
                $query->where("metadata_json->{$field}", $values[0]);
            } elseif ($values !== []) {
                $query->whereIn("metadata_json->{$field}", $values);
            }
        }

        if (isset($filters['required'])) {
            $query->where('required', filter_var($filters['required'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['recipe_blocking_capable'])) {
            $query->where('metadata_json->recipe_blocking_capable', filter_var($filters['recipe_blocking_capable'], FILTER_VALIDATE_BOOLEAN));
        }

        $limit = max(1, min(200, is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20));

        return $query->limit($limit)->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function findRun(string $runId, array $filters = []): ?AtlasToolRun
    {
        if (! DatabaseTableAvailability::has('atlas_tool_runs')) {
            return null;
        }

        $query = AtlasToolRun::query()
            ->with(['tool', 'artifacts', 'findings'])
            ->whereKey($runId);

        $workspaceHash = $this->workspaceHash($filters['workspace'] ?? null);
        if ($workspaceHash !== null) {
            $query->where('workspace_hash', $workspaceHash);
        }

        return $query->first();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>|null
     */
    public function exportRun(string $runId, array $filters = []): ?array
    {
        $run = $this->findRun($runId, $filters);
        if (! $run) {
            return null;
        }

        return [
            'schema' => 'atlas.tool_evidence.v1',
            'generated_at' => now()->toISOString(),
            'receipt' => [
                'schema_version' => data_get($run->metadata_json, 'receipt_schema_version') ?: 'atlas.tool_evidence_receipt.v1',
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'workspace_hash' => $run->workspace_hash,
                'command_hash' => $run->command_hash,
                'summary_hash' => data_get($run->metadata_json, 'summary_hash'),
                'normalized_result_hash' => data_get($run->metadata_json, 'normalized_result_hash'),
                'evidence_receipt_hash' => data_get($run->metadata_json, 'evidence_receipt_hash'),
                'action_runtime_contract' => data_get($run->metadata_json, 'action_runtime_contract') ?: [
                    'schema_version' => 'atlas.tool_action_runtime.contract.v1',
                    'mode' => 'evidence_export',
                    'provider_dispatch_allowed' => false,
                    'runtime_policy_mutation_allowed' => false,
                    'agent_control_plane_allowed' => false,
                    'operator_approval_required_for_execution' => true,
                ],
                'raw_command_exposed' => false,
                'raw_output_exposed' => false,
                'workspace_path_exposed' => false,
                'provider_dispatch_allowed' => false,
                'runtime_policy_mutation_allowed' => false,
            ],
            'run' => [
                'id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'surface' => $run->surface,
                'workspace_hash' => $run->workspace_hash,
                'run_context_type' => $run->run_context_type,
                'run_context_id' => $run->run_context_id,
                'status' => $run->status,
                'required' => $run->required,
                'failure_policy' => $run->failure_policy,
                'policy_decision' => $run->policy_decision,
                'command_hash' => $run->command_hash,
                'exit_code' => $run->exit_code,
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'duration_ms' => $run->duration_ms,
                'summary' => $run->summary_json,
                'policy' => $run->policy_decision_json,
                'metadata' => [
                    'source' => data_get($run->metadata_json, 'source'),
                    'category' => data_get($run->metadata_json, 'category'),
                    'reason' => $this->exportText(data_get($run->metadata_json, 'reason'), $run->workspace),
                    'recipe' => data_get($run->metadata_json, 'recipe'),
                    'recipe_category' => data_get($run->metadata_json, 'recipe_category'),
                    'recipe_recommended_surface' => data_get($run->metadata_json, 'recipe_recommended_surface'),
                    'recipe_creates_evidence' => data_get($run->metadata_json, 'recipe_creates_evidence'),
                    'recipe_blocking_capable' => data_get($run->metadata_json, 'recipe_blocking_capable'),
                    'execution_origin' => data_get($run->metadata_json, 'execution_origin'),
                    'receipt_schema_version' => data_get($run->metadata_json, 'receipt_schema_version'),
                    'summary_hash' => data_get($run->metadata_json, 'summary_hash'),
                    'normalized_result_hash' => data_get($run->metadata_json, 'normalized_result_hash'),
                    'evidence_receipt_hash' => data_get($run->metadata_json, 'evidence_receipt_hash'),
                    'action_runtime_contract_schema_version' => data_get($run->metadata_json, 'action_runtime_contract.schema_version'),
                ],
            ],
            'artifacts' => $run->artifacts
                ->map(fn ($artifact): array => [
                    'id' => $artifact->id,
                    'type' => $artifact->type,
                    'filename' => $artifact->filename,
                    'mime_type' => $artifact->mime_type,
                    'size_bytes' => $artifact->size_bytes,
                    'sha256' => $artifact->sha256,
                    'is_redacted' => $artifact->is_redacted,
                ])
                ->values()
                ->all(),
            'findings' => $run->findings
                ->map(fn ($finding): array => [
                    'id' => $finding->id,
                    'rule_id' => $finding->rule_id,
                    'title' => $this->exportText($finding->title, $run->workspace),
                    'message' => $this->exportText($finding->message, $run->workspace),
                    'severity' => $finding->severity,
                    'confidence' => $finding->confidence,
                    'file_path' => $this->exportFilePath($finding->file_path, $run->workspace),
                    'line' => $finding->line,
                    'end_line' => $finding->end_line,
                    'fingerprint' => $finding->fingerprint,
                    'blocks_resolved' => $finding->blocks_resolved,
                    'waiver_id' => $finding->waiver_id,
                    'status' => $finding->status,
                    'waiver' => $this->exportWaiver((array) data_get($finding->metadata_json, 'waiver', [])),
                ])
                ->values()
                ->all(),
            'integrity' => [
                'run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'workspace_hash' => $run->workspace_hash,
                'command_hash' => $run->command_hash,
                'receipt_schema_version' => data_get($run->metadata_json, 'receipt_schema_version'),
                'summary_hash' => data_get($run->metadata_json, 'summary_hash'),
                'normalized_result_hash' => data_get($run->metadata_json, 'normalized_result_hash'),
                'evidence_receipt_hash' => data_get($run->metadata_json, 'evidence_receipt_hash'),
                'artifact_hashes' => $run->artifacts
                    ->map(fn ($artifact): array => [
                        'id' => $artifact->id,
                        'type' => $artifact->type,
                        'filename' => $artifact->filename,
                        'sha256' => $artifact->sha256,
                        'size_bytes' => $artifact->size_bytes,
                    ])
                    ->values()
                    ->all(),
                'finding_fingerprints' => $run->findings
                    ->pluck('fingerprint')
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function workspaceHash(mixed $workspace): ?string
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return hash('sha256', realpath($workspace) ?: $workspace);
    }

    private function exportFilePath(mixed $path, mixed $workspace): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        $realPath = realpath($path) ?: $path;
        $workspace = is_string($workspace) ? (realpath($workspace) ?: $workspace) : null;
        if ($workspace && str_starts_with($realPath, $workspace.DIRECTORY_SEPARATOR)) {
            return ltrim(substr($realPath, strlen($workspace)), DIRECTORY_SEPARATOR);
        }

        if (str_starts_with($realPath, DIRECTORY_SEPARATOR)) {
            return basename($realPath);
        }

        return $realPath;
    }

    private function exportText(mixed $value, mixed $workspace): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = AtlasSecurity::redactString($value);
        $workspace = is_string($workspace) ? (realpath($workspace) ?: $workspace) : null;
        foreach ($this->workspacePathAliases($workspace) as $alias) {
            $text = str_replace($alias, '[workspace]', $text);
        }

        return $text;
    }

    /**
     * @param  array<string,mixed>  $waiver
     * @return array<string,mixed>|null
     */
    private function exportWaiver(array $waiver): ?array
    {
        if ($waiver === []) {
            return null;
        }

        return array_filter([
            'id' => is_string($waiver['id'] ?? null) ? $waiver['id'] : null,
            'reason' => $this->exportText($waiver['reason'] ?? null, null),
            'waived_by' => is_string($waiver['waived_by'] ?? null) ? $waiver['waived_by'] : null,
            'waived_at' => is_string($waiver['waived_at'] ?? null) ? $waiver['waived_at'] : null,
            'waived_until' => is_string($waiver['waived_until'] ?? null) ? $waiver['waived_until'] : null,
            'source' => is_string($waiver['source'] ?? null) ? $waiver['source'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int,string>
     */
    private function workspacePathAliases(?string $workspace): array
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return [];
        }

        $aliases = [$workspace];
        if (str_starts_with($workspace, '/private/')) {
            $aliases[] = substr($workspace, strlen('/private'));
        } elseif (str_starts_with($workspace, '/')) {
            $aliases[] = '/private'.$workspace;
        }

        return array_values(array_unique(array_filter($aliases)));
    }
}
