<?php

namespace App\Services\Tools;

use App\Models\AtlasToolArtifact;
use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AtlasToolEvidenceStore
{
    public function __construct(
        private readonly AtlasToolRegistryService $registry,
        private readonly AtlasToolResultNormalizer $normalizer,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function recordExternalToolResult(string $toolSlug, string $workspace, array $payload, array $context = []): ?AtlasToolRun
    {
        if (! $this->evidenceTablesAvailable()) {
            return null;
        }

        $workspace = realpath($workspace) ?: $workspace;
        $definition = $this->registry->definition($toolSlug);
        $normalized = $this->normalizer->normalize($toolSlug, $payload);
        $summaryHash = $this->stableHash($normalized['summary'] ?? []);
        $normalizedResultHash = $this->stableHash($normalized);
        $evidenceReceiptHash = $this->stableHash([
            'tool_slug' => $toolSlug,
            'workspace_hash' => hash('sha256', $workspace),
            'run_context_type' => $context['run_context_type'] ?? null,
            'run_context_id' => $context['run_context_id'] ?? null,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'required' => (bool) ($payload['required'] ?? false),
            'summary_hash' => $summaryHash,
            'normalized_result_hash' => $normalizedResultHash,
        ]);

        $run = DB::transaction(function () use ($context, $definition, $evidenceReceiptHash, $normalized, $normalizedResultHash, $payload, $summaryHash, $toolSlug, $workspace): AtlasToolRun {
            $run = AtlasToolRun::query()->create([
                'tool_definition_id' => $definition?->id,
                'tool_slug' => $toolSlug,
                'surface' => (string) ($context['surface'] ?? 'engineering'),
                'workspace_hash' => hash('sha256', $workspace),
                'workspace' => $workspace,
                'run_context_type' => $context['run_context_type'] ?? null,
                'run_context_id' => $context['run_context_id'] ?? null,
                'status' => (string) ($payload['status'] ?? 'unknown'),
                'required' => (bool) ($payload['required'] ?? false),
                'failure_policy' => (string) ($payload['failure_policy'] ?? $definition?->default_failure_policy ?? 'advisory'),
                'policy_decision' => (string) ($payload['policy_decision'] ?? 'allowed'),
                'command_hash' => isset($payload['command']) ? hash('sha256', json_encode($payload['command'], JSON_UNESCAPED_SLASHES) ?: '') : null,
                'exit_code' => isset($payload['exit_code']) && is_numeric($payload['exit_code']) ? (int) $payload['exit_code'] : null,
                'started_at' => $context['started_at'] ?? now(),
                'finished_at' => $context['finished_at'] ?? now(),
                'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                'summary_json' => $normalized['summary'],
                'normalized_result_json' => $normalized,
                'policy_decision_json' => (array) ($payload['policy_decision_json'] ?? []),
                'metadata_json' => [
                    'source' => $context['source'] ?? 'external_result',
                    ...$this->definitionMetadata($definition),
                    'category' => $payload['category'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'command' => isset($payload['command']) ? AtlasSecurity::redactCommand((array) $payload['command']) : null,
                    'summary_hash' => $summaryHash,
                    'normalized_result_hash' => $normalizedResultHash,
                    'evidence_receipt_hash' => $evidenceReceiptHash,
                    'receipt_schema_version' => 'atlas.tool_evidence_receipt.v1',
                    'action_runtime_contract' => $this->actionRuntimeContract($toolSlug, $payload, $context),
                    ...((array) ($context['metadata'] ?? [])),
                ],
            ]);

            foreach ((array) ($payload['artifact_paths'] ?? []) as $type => $path) {
                if (is_string($path)) {
                    $this->attachPath($run, (string) $type, $path);
                }
            }

            foreach ($normalized['findings'] as $finding) {
                $this->recordFinding($run, $finding);
            }

            return $run->refresh();
        });

        $this->recordLedgerToolEvidence($run->refresh(), $normalized, $context);

        return $run->refresh();
    }

    private function evidenceTablesAvailable(): bool
    {
        return DatabaseTableAvailability::all([
            'atlas_tool_definitions',
            'atlas_tool_runs',
            'atlas_tool_artifacts',
            'atlas_tool_findings',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function actionRuntimeContract(string $toolSlug, array $payload, array $context): array
    {
        return [
            'schema_version' => 'atlas.tool_action_runtime.contract.v1',
            'tool_slug' => $toolSlug,
            'mode' => 'evidence_recording',
            'surface' => (string) ($context['surface'] ?? 'engineering'),
            'run_context_type' => $context['run_context_type'] ?? null,
            'run_context_id' => $context['run_context_id'] ?? null,
            'command_hash' => isset($payload['command']) ? hash('sha256', json_encode($payload['command'], JSON_UNESCAPED_SLASHES) ?: '') : null,
            'raw_command_exposed' => false,
            'raw_output_exposed' => false,
            'workspace_path_exposed' => false,
            'provider_dispatch_allowed' => false,
            'runtime_policy_mutation_allowed' => false,
            'agent_control_plane_allowed' => false,
            'operator_approval_required_for_execution' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  array<string,mixed>  $context
     */
    private function recordLedgerToolEvidence(AtlasToolRun $run, array $normalized, array $context): void
    {
        $envelopeId = (string) (
            $context['envelope_id']
            ?? data_get($context, 'decision_receipt.receipt_v2.envelope_id')
            ?? ($run->run_context_id ? "{$run->run_context_type}:{$run->run_context_id}" : $run->id)
        );
        $receiptId = $context['receipt_id']
            ?? data_get($context, 'decision_receipt.receipt_v2.receipt_id')
            ?? null;

        try {
            $this->ledger->record(LedgerEventType::ToolEvidenceRecorded, [
                'envelope_id' => $envelopeId,
                'receipt_id' => $receiptId,
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'surface' => $run->surface,
                'workspace_hash' => $run->workspace_hash,
                'run_context_type' => $run->run_context_type,
                'run_context_id' => $run->run_context_id,
                'status' => $run->status,
                'required' => $run->required,
                'failure_policy' => $run->failure_policy,
                'policy_decision' => $run->policy_decision,
                'authority_group' => data_get($run->metadata_json, 'authority_group'),
                'authority_role' => data_get($run->metadata_json, 'authority_role'),
                'command_hash' => $run->command_hash,
                'exit_code' => $run->exit_code,
                'duration_ms' => $run->duration_ms,
                'finding_count' => count((array) ($normalized['findings'] ?? [])),
                'blocking_failure_count' => count((array) ($normalized['blocking_failures'] ?? [])),
                'summary_hash' => data_get($run->metadata_json, 'summary_hash'),
                'normalized_result_hash' => data_get($run->metadata_json, 'normalized_result_hash'),
                'evidence_receipt_hash' => data_get($run->metadata_json, 'evidence_receipt_hash'),
                'receipt_schema_version' => data_get($run->metadata_json, 'receipt_schema_version'),
            ], [
                'tenant_id' => (string) ($context['tenant_id'] ?? 'default'),
                'operator_id' => (string) ($context['operator_id'] ?? 'system'),
                'envelope_id' => $envelopeId,
                'receipt_id' => $receiptId ? (string) $receiptId : null,
                'trace_id' => is_string($context['trace_id'] ?? null) ? $context['trace_id'] : null,
                'correlation_id' => (string) ($context['correlation_id'] ?? $envelopeId),
                'emitter_stage' => 'atlas.tools',
                'emitter_version' => 'tool-evidence-store-v1',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function stableHash(mixed $payload): string
    {
        $encoded = json_encode($this->sortKeysRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? '' : $encoded);
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function definitionMetadata(?AtlasToolDefinition $definition): array
    {
        if (! $definition) {
            return [];
        }

        return [
            'tool_category' => $definition->category,
            'tool_type' => $definition->type,
            'execution_tier' => $definition->execution_tier ?? data_get($definition->metadata, 'execution_tier'),
            'expected_cost' => $definition->expected_cost ?? data_get($definition->metadata, 'expected_cost'),
            'default_trigger' => $definition->default_trigger ?? data_get($definition->metadata, 'default_trigger'),
            'authority_group' => $definition->authority_group ?? data_get($definition->metadata, 'authority_group'),
            'authority_role' => $definition->authority_role ?? data_get($definition->metadata, 'authority_role'),
        ];
    }

    public function attachText(AtlasToolRun $run, string $type, string $filename, string $content): AtlasToolArtifact
    {
        $root = storage_path('app/atlas-tool-runs/'.$run->id);
        File::ensureDirectoryExists($root);
        $path = $root.'/'.$filename;
        File::put($path, AtlasSecurity::redactString($content));

        return $this->attachPath($run, $type, $path);
    }

    public function attachPath(AtlasToolRun $run, string $type, string $path): AtlasToolArtifact
    {
        $real = realpath($path) ?: $path;
        $artifact = AtlasToolArtifact::query()->create([
            'tool_run_id' => $run->id,
            'type' => $type,
            'path' => $real,
            'filename' => basename($real),
            'mime_type' => $this->mimeType($real),
            'size_bytes' => File::isFile($real) ? File::size($real) : 0,
            'sha256' => File::isFile($real) ? hash_file('sha256', $real) : hash('sha256', $real),
            'is_redacted' => true,
            'preview_json' => $this->preview($real),
        ]);

        if ($type === 'stdout' && $run->stdout_artifact_id === null) {
            $run->forceFill(['stdout_artifact_id' => $artifact->id])->save();
        }

        if ($type === 'stderr' && $run->stderr_artifact_id === null) {
            $run->forceFill(['stderr_artifact_id' => $artifact->id])->save();
        }

        return $artifact;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function recordFinding(AtlasToolRun $run, array $finding): AtlasToolFinding
    {
        return AtlasToolFinding::query()->create([
            'tool_run_id' => $run->id,
            'rule_id' => $finding['rule_id'] ?? null,
            'title' => (string) ($finding['title'] ?? 'Tool finding'),
            'message' => $finding['message'] ?? null,
            'severity' => (string) ($finding['severity'] ?? 'medium'),
            'confidence' => $finding['confidence'] ?? null,
            'file_path' => $finding['file_path'] ?? null,
            'line' => $finding['line'] ?? null,
            'end_line' => $finding['end_line'] ?? null,
            'fingerprint' => $finding['fingerprint'] ?? null,
            'blocks_resolved' => (bool) ($finding['blocks_resolved'] ?? false),
            'status' => 'open',
            'metadata_json' => (array) ($finding['metadata'] ?? []),
        ]);
    }

    private function mimeType(string $path): ?string
    {
        return str_ends_with($path, '.json') ? 'application/json' : (str_ends_with($path, '.txt') ? 'text/plain' : null);
    }

    /**
     * @return array<string,mixed>
     */
    private function preview(string $path): array
    {
        if (! File::isFile($path)) {
            return [];
        }

        $content = File::get($path);

        return [
            'excerpt' => mb_substr(AtlasSecurity::redactString($content), 0, 2000),
            'truncated' => mb_strlen($content) > 2000,
        ];
    }
}
