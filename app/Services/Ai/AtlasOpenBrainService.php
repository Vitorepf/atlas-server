<?php

namespace App\Services\Ai;

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Facades\Schema;

class AtlasOpenBrainService
{
    public function __construct(private readonly AiContextPackBuilder $contextPacks) {}

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function contextPack(array $data, string $surface = 'api'): array
    {
        $objective = trim((string) ($data['objective'] ?? ''));
        $workspace = $this->workspace($data['workspace'] ?? data_get($data, 'payload.workspace'));
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($workspace !== null) {
            $payload['workspace'] = $workspace;
        }
        if (is_string($data['task_type'] ?? null) && $data['task_type'] !== '') {
            $payload['task_type'] = $data['task_type'];
        }
        if (is_string($data['desired_mode'] ?? null) && $data['desired_mode'] !== '') {
            $payload['atlas_workflow_mode'] = $data['desired_mode'];
        }

        $task = AiTaskRequest::fromInput($objective, [
            'source_type' => $surface === 'cli' ? 'manual' : 'system',
            'payload' => $payload,
        ], [
            'agent' => (string) ($data['agent'] ?? 'orquestrador'),
            'intent' => (string) ($data['intent'] ?? 'memory_context_export'),
        ]);

        $pack = $this->contextPacks->build($objective, $task, array_merge([
            'payload' => $payload,
        ], is_array($data['options'] ?? null) ? $data['options'] : []));

        $contextPack = $pack->toArray();
        $hash = hash('sha256', json_encode($contextPack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $contextRefs = $pack->contextRefs();
        $memoryRefs = collect($contextRefs)
            ->filter(fn (mixed $ref): bool => is_array($ref) && ($ref['type'] ?? null) === 'atlas_memory_entry')
            ->values();

        $summary = [
            'context_refs_count' => count($contextRefs),
            'memory_refs_count' => $memoryRefs->count(),
            'recall_count' => count((array) data_get($contextPack, 'memory.recall', [])),
            'registry_count' => count((array) data_get($contextPack, 'memory.registry', [])),
            'verbatim_count' => count((array) data_get($contextPack, 'memory.verbatim', [])),
            'semantic_count' => count((array) data_get($contextPack, 'memory.semantic', [])),
            'provider_safe' => true,
        ];
        $auditTableExists = Schema::hasTable('atlas_open_brain_access_logs');
        $safety = $this->safetySummary($summary, $auditTableExists, $auditTableExists ? $hash : null);

        $audit = $this->recordAudit([
            'surface' => $surface,
            'requester' => $this->requester($data),
            'action' => 'context_pack_export',
            'status' => 'completed',
            'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
            'workspace_label' => $workspace ? basename($workspace) : null,
            'context_pack_hash' => $hash,
            'context_refs_count' => $summary['context_refs_count'],
            'memory_refs_count' => $summary['memory_refs_count'],
            'provider_safe' => true,
            'query_json' => [
                'objective_hash' => hash('sha256', $objective),
                'objective_excerpt_redacted' => true,
                'objective_length' => mb_strlen($objective),
                'task_type' => data_get($contextPack, 'task.type'),
                'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
                'workspace_label' => $workspace ? basename($workspace) : null,
            ],
            'result_summary_json' => $summary + ['safety' => $safety],
            'metadata' => [
                'schema_version' => 1,
                'source' => 'atlas_open_brain_service',
                'query_redaction' => 'hash_only_no_raw_objective_or_workspace_path',
            ],
            'accessed_at' => now(),
        ]);
        $safety = $this->safetySummary($summary, $audit !== null, is_string($audit['context_pack_hash'] ?? null) ? (string) $audit['context_pack_hash'] : null);

        $result = [
            'ok' => true,
            'schema_version' => 1,
            'context_pack_hash' => $hash,
            'context_pack' => $contextPack,
            'context_refs' => $contextRefs,
            'summary' => $summary,
            'safety' => $safety,
            'audit' => $audit,
        ];

        if ((bool) ($data['include_prompt'] ?? false)) {
            $result['prompt_section'] = $pack->toPromptSection();
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>|null
     */
    private function recordAudit(array $attributes): ?array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return null;
        }

        $log = AtlasOpenBrainAccessLog::query()->create($attributes);

        return [
            'id' => $log->id,
            'surface' => $log->surface,
            'requester' => $log->requester,
            'action' => $log->action,
            'status' => $log->status,
            'context_pack_hash' => $log->context_pack_hash,
            'context_refs_count' => $log->context_refs_count,
            'memory_refs_count' => $log->memory_refs_count,
            'provider_safe' => $log->provider_safe,
            'accessed_at' => $log->accessed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function safetySummary(array $summary, bool $auditPersisted, ?string $persistedContextPackHash): array
    {
        return [
            'schema_version' => 'atlas.open_brain.context_pack_safety.v1',
            'provider_safe_only' => true,
            'provider_export_allowed' => true,
            'open_brain_context_allowed' => true,
            'raw_content_exposed' => false,
            'raw_content_persisted' => false,
            'audit_query_raw_content_persisted' => false,
            'audit_persisted' => $auditPersisted,
            'context_pack_hash_persisted' => $persistedContextPackHash !== null && $persistedContextPackHash !== '',
            'context_refs_count' => (int) ($summary['context_refs_count'] ?? 0),
            'memory_refs_count' => (int) ($summary['memory_refs_count'] ?? 0),
            'recall_count' => (int) ($summary['recall_count'] ?? 0),
            'registry_count' => (int) ($summary['registry_count'] ?? 0),
            'verbatim_count' => (int) ($summary['verbatim_count'] ?? 0),
            'semantic_count' => (int) ($summary['semantic_count'] ?? 0),
        ];
    }

    private function workspace(mixed $workspace): ?string
    {
        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            return config('atlas.ai.workdir') ?: null;
        }

        $workspace = trim((string) $workspace);

        return realpath($workspace) ?: $workspace;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function requester(array $data): ?string
    {
        $requester = $data['requester'] ?? null;

        return is_scalar($requester) && trim((string) $requester) !== '' ? mb_substr(trim((string) $requester), 0, 120) : null;
    }
}
