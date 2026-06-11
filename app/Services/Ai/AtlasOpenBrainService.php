<?php

namespace App\Services\Ai;

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\ValueObjects\AiContextPack;
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
        if (is_array($contextPack['context_delivery_policy'] ?? null)) {
            $summary['context_delivery_policy'] = $this->contextDeliveryPolicySummary((array) $contextPack['context_delivery_policy']);
        }

        $promptSection = null;
        if ((bool) ($data['include_prompt'] ?? false)) {
            $promptMode = $this->promptMode($data);
            $promptSection = $this->promptSection($pack, $promptMode);
            $fullPromptSection = $promptMode === 'compact' ? $pack->toPromptSection() : null;
            $summary['prompt'] = $this->promptMetrics($promptSection, $promptMode, $fullPromptSection);
        }

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

        if ($promptSection !== null) {
            $result['prompt_section'] = $promptSection;
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
        $safety = [
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

        if (is_array($summary['prompt'] ?? null)) {
            $safety['prompt_mode'] = (string) data_get($summary, 'prompt.mode', 'unknown');
            $safety['prompt_chars'] = (int) data_get($summary, 'prompt.chars', 0);
            $safety['prompt_estimated_tokens'] = (int) data_get($summary, 'prompt.estimated_tokens', 0);
            $safety['prompt_saved_chars'] = (int) data_get($summary, 'prompt.saved_chars', 0);
            $safety['prompt_estimated_tokens_saved'] = (int) data_get($summary, 'prompt.estimated_tokens_saved', 0);
            $safety['prompt_raw_prompt_persisted'] = false;
        }

        return $safety;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function contextDeliveryPolicySummary(array $policy): array
    {
        return [
            'schema_version' => (string) ($policy['schema_version'] ?? 'atlas.token_economy.context_delivery_policy.v1'),
            'status' => (string) ($policy['status'] ?? 'unknown'),
            'delivery_mode' => (string) ($policy['delivery_mode'] ?? 'unknown'),
            'initial_context_token_budget' => (int) ($policy['initial_context_token_budget'] ?? 0),
            'expansion_token_reserve' => (int) ($policy['expansion_token_reserve'] ?? 0),
            'initial_ref_limit' => (int) ($policy['initial_ref_limit'] ?? 0),
            'initial_source_types' => array_values((array) ($policy['initial_source_types'] ?? [])),
            'deferred_source_types' => array_values((array) ($policy['deferred_source_types'] ?? [])),
            'guarded_required_source_types' => array_values((array) ($policy['guarded_required_source_types'] ?? [])),
            'quality_gate_hint' => (string) ($policy['quality_gate_hint'] ?? 'feedback_guided_staging_allowed'),
            'advisory_only' => true,
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
    private function promptMode(array $data): string
    {
        $mode = $data['prompt_mode'] ?? data_get($data, 'options.prompt_mode');
        if (is_scalar($mode) && trim((string) $mode) === 'full') {
            return 'full';
        }

        return 'compact';
    }

    private function promptSection(AiContextPack $pack, string $mode): string
    {
        return $mode === 'full'
            ? $pack->toPromptSection()
            : $pack->toCompactPromptSection();
    }

    /**
     * @return array<string,mixed>
     */
    private function promptMetrics(string $promptSection, string $mode, ?string $fullPromptSection = null): array
    {
        $chars = $this->charCount($promptSection);
        $lines = $this->lineCount($promptSection);
        $estimatedTokens = $this->estimatedTokens($chars);
        $fullChars = $fullPromptSection === null ? $chars : $this->charCount($fullPromptSection);
        $fullLines = $fullPromptSection === null ? $lines : $this->lineCount($fullPromptSection);
        $fullEstimatedTokens = $this->estimatedTokens($fullChars);
        $savedChars = max(0, $fullChars - $chars);
        $savedLines = max(0, $fullLines - $lines);
        $savedTokens = max(0, $fullEstimatedTokens - $estimatedTokens);

        return [
            'schema_version' => 'atlas.open_brain.prompt_metrics.v1',
            'mode' => $mode,
            'baseline_mode' => 'full',
            'chars' => $chars,
            'lines' => $lines,
            'estimated_token_chars_per_token' => 4,
            'estimated_tokens' => $estimatedTokens,
            'full_chars' => $fullChars,
            'full_lines' => $fullLines,
            'full_estimated_tokens' => $fullEstimatedTokens,
            'saved_chars' => $savedChars,
            'saved_lines' => $savedLines,
            'estimated_tokens_saved' => $savedTokens,
            'compact_to_full_ratio' => $fullChars > 0 ? round($chars / $fullChars, 4) : null,
            'savings_ratio' => $fullChars > 0 ? round($savedChars / $fullChars, 4) : 0.0,
            'raw_prompt_persisted' => false,
            'raw_bodies_deferred' => $mode === 'compact',
            'expansion_handle_count' => $this->expansionHandleCount($promptSection),
            'baseline_generated_for_metrics_only' => $fullPromptSection !== null,
            'provider_safe' => true,
        ];
    }

    private function charCount(string $value): int
    {
        return mb_strlen($value);
    }

    private function lineCount(string $value): int
    {
        return $value === '' ? 0 : substr_count($value, "\n") + 1;
    }

    private function estimatedTokens(int $chars): int
    {
        return (int) ceil($chars / 4);
    }

    private function expansionHandleCount(string $promptSection): int
    {
        preg_match_all('/\b(?:expand|recheck):[a-z0-9_.:-]+/i', $promptSection, $matches);

        return count(array_unique($matches[0] ?? []));
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
