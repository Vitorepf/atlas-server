<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\AtlasOpenBrainContextExpansionService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasRecallUncertaintyMap;
use Illuminate\Support\Facades\Schema;

/**
 * GOD-DEBULK: context/recall retrieval tool family extracted verbatim from
 * AtlasOpenBrainMcpService — atlas_memory_recall / atlas_context_expand /
 * atlas_context_feedback / atlas_open_brain_context_pack, plus the shared
 * contextPackOptions builder that the façade's atlas_context_pack still consumes.
 * Bodies are byte-identical to the pre-split service; the façade delegates here.
 */
class ContextTools
{
    use OpenBrainMcpToolInput;

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $recall,
        private readonly AtlasOpenBrainContextPackService $contextPack,
        private readonly AtlasOpenBrainContextExpansionService $contextExpansion,
        private readonly AtlasRetrievalFeedbackLoopService $retrievalFeedback,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function memoryRecall(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? '') ?? '';
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'query_required',
            ];
        }

        $context = $this->object($arguments['context'] ?? []);
        $workspace = $this->workspace($context['workspace'] ?? ($arguments['workspace'] ?? null));
        if ($workspace !== null) {
            $context['workspace'] = $workspace;
        }

        $recall = $this->recall->recall(
            $query,
            $context,
            $this->object($arguments['filters'] ?? []),
            $this->object($arguments['options'] ?? []),
        );

        return [
            'ok' => true,
            'tool' => 'atlas_memory_recall',
            'memory_recall' => $recall,
            // T4-S4 (Obra #17) — the recall's UNCERTAINTY MAP: how confident is this
            // retrieval? A weak/flat recall is flagged so the caller treats it as a weak
            // signal (and can ask for more), not as settled truth. Pure read over the
            // recall result; zero extra provider spend.
            'uncertainty' => (new AtlasRecallUncertaintyMap)->forRecall($recall),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function contextPack(array $arguments): array
    {
        $objective = $this->string($arguments['objective'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $packArguments = $arguments;
        $packArguments['task'] = $objective;
        $pack = $this->contextPack->packFor($objective, $this->contextPackOptions($packArguments));
        $workspace = $this->workspace($arguments['workspace'] ?? data_get($arguments, 'payload.workspace'));
        $audit = $this->recordLegacyContextPackAudit($arguments, $objective, $workspace, $pack);
        $summary = [
            'context_refs_count' => (int) data_get($pack, 'context_feedback_request.delivered_ref_count', 0),
            'memory_refs_count' => count((array) ($pack['memory'] ?? [])),
            'provider_safe' => true,
            'runtime_version' => data_get($pack, 'provenance.aobg_runtime.runtime_version'),
            'deprecated_alias_of' => 'atlas_context_pack',
        ];

        return [
            'ok' => true,
            'tool' => 'atlas_open_brain_context_pack',
            'deprecated_alias_of' => 'atlas_context_pack',
            'provider_bound' => true,
            'pack' => $pack,
            'open_brain' => [
                'ok' => true,
                'schema_version' => 1,
                'context_pack_hash' => $pack['context_pack_hash'] ?? null,
                'context_pack' => $pack,
                'context_refs' => (array) data_get($pack, 'context_feedback_request.delivered_context_refs', []),
                'summary' => $summary,
                'safety' => [
                    'provider_safe' => true,
                    'audit_persisted' => $audit !== null,
                    'raw_text_exposed' => false,
                ],
                'audit' => $audit,
                'deprecated_alias_of' => 'atlas_context_pack',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>|null
     */
    private function recordLegacyContextPackAudit(array $arguments, string $objective, ?string $workspace, array $pack): ?array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return null;
        }

        $log = AtlasOpenBrainAccessLog::query()->create([
            'surface' => 'mcp',
            'requester' => $this->string($arguments['requester'] ?? null) ?: 'mcp-client',
            'action' => 'context_pack_export',
            'status' => 'completed',
            'workspace_hash' => $workspace !== null ? hash('sha256', $workspace) : null,
            'workspace_label' => $workspace !== null ? basename($workspace) : null,
            'context_pack_hash' => $pack['context_pack_hash'] ?? null,
            'context_refs_count' => (int) data_get($pack, 'context_feedback_request.delivered_ref_count', 0),
            'memory_refs_count' => count((array) ($pack['memory'] ?? [])),
            'provider_safe' => true,
            'query_json' => [
                'objective_hash' => hash('sha256', $objective),
                'objective_excerpt_redacted' => true,
                'objective_length' => mb_strlen($objective),
                'workspace_hash' => $workspace !== null ? hash('sha256', $workspace) : null,
                'workspace_label' => $workspace !== null ? basename($workspace) : null,
            ],
            'result_summary_json' => [
                'schema_version' => 'atlas.aobg.legacy_context_pack_alias.v1',
                'deprecated_alias_of' => 'atlas_context_pack',
                'runtime_version' => data_get($pack, 'provenance.aobg_runtime.runtime_version'),
                'provider_safe' => true,
            ],
            'metadata' => [
                'schema_version' => 2,
                'source' => 'atlas_open_brain_mcp_service',
                'alias_of' => 'atlas_context_pack',
                'query_redaction' => 'hash_only_no_raw_objective_or_workspace_path',
            ],
            'accessed_at' => now(),
        ]);

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
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function contextExpand(array $arguments): array
    {
        $handle = $this->string($arguments['handle'] ?? '') ?? '';
        if ($handle === '') {
            return [
                'ok' => false,
                'error' => 'handle_required',
            ];
        }

        $objective = $this->string($arguments['objective'] ?? $arguments['task'] ?? $arguments['query'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);

        return [
            'ok' => true,
            'tool' => 'atlas_context_expand',
            'context_expansion' => $this->contextExpansion->expand([
                'handle' => $handle,
                'objective' => $objective,
                'workspace' => $workspace,
                'task_type' => $this->string($arguments['task_type'] ?? null) ?: 'dev',
                'domain' => $this->string($arguments['domain'] ?? null) ?: 'atlas',
                'risk_level' => $this->string($arguments['risk_level'] ?? $arguments['risk'] ?? null) ?: 'low',
                'max_refs' => $this->positiveInt($arguments['max_refs'] ?? null) ?: 6,
                'budget' => $this->positiveInt($arguments['budget'] ?? null) ?: 3200,
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function contextFeedback(array $arguments): array
    {
        $objective = $this->string($arguments['objective'] ?? $arguments['task'] ?? $arguments['query'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $contextPackHash = $this->string($arguments['context_pack_hash'] ?? null);
        $retrievalReceiptId = $this->string($arguments['retrieval_receipt_id'] ?? null) ?: $contextPackHash;
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $missedSources = $this->stringList($arguments['missed_required_sources'] ?? $arguments['missed_sources'] ?? []);
        $input = [
            'objective' => $objective,
            'workspace' => $workspace,
            'task_type' => $this->string($arguments['task_type'] ?? null) ?: 'dev',
            'domain' => $this->string($arguments['domain'] ?? null) ?: 'atlas',
            'risk_level' => $this->string($arguments['risk_level'] ?? $arguments['risk'] ?? null) ?: 'low',
            'outcome_status' => $this->string($arguments['outcome_status'] ?? $arguments['outcome'] ?? null) ?: 'unknown',
            'max_refs' => $this->positiveInt($arguments['max_refs'] ?? null) ?: 8,
            'delivered_context_refs' => $this->stringList($arguments['delivered_context_refs'] ?? $arguments['delivered_refs'] ?? []),
            'used_context_refs' => $this->stringList($arguments['used_context_refs'] ?? $arguments['used_refs'] ?? []),
            'noise_context_refs' => $this->stringList($arguments['noise_context_refs'] ?? $arguments['noise_refs'] ?? []),
            'missed_required_sources' => array_map(
                static fn (string $source): array => [
                    'source_type' => $source,
                    'reason' => 'mcp_reported_missing_source',
                ],
                $missedSources,
            ),
            'record' => (bool) ($arguments['record'] ?? false),
        ];

        if (($flowId = $this->string($arguments['flow_id'] ?? null)) !== null) {
            $input['flow_id'] = $flowId;
        }
        if ($retrievalReceiptId !== null) {
            $input['retrieval_receipt_id'] = $retrievalReceiptId;
        }
        if ($contextPackHash !== null) {
            $input['context_pack_hash'] = $contextPackHash;
        }
        if (is_numeric($arguments['post_execution_utility'] ?? $arguments['utility'] ?? null)) {
            $input['post_execution_utility'] = max(0, min(100, (int) ($arguments['post_execution_utility'] ?? $arguments['utility'])));
        }
        if (($runOutcomeId = $this->string($arguments['run_outcome_id'] ?? null)) !== null) {
            $input['run_outcome_id'] = $runOutcomeId;
        }
        if (($memoryCandidateId = $this->string($arguments['memory_candidate_id'] ?? null)) !== null) {
            $input['memory_candidate_id'] = $memoryCandidateId;
        }

        return [
            'ok' => true,
            'tool' => 'atlas_context_feedback',
            'context_feedback' => $this->retrievalFeedback->capture($input),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function contextPackOptions(array $arguments): array
    {
        $opts = [];

        if ($this->string($arguments['workspace'] ?? null) !== null) {
            $opts['workspace'] = $this->workspace($arguments['workspace']);
        } elseif ($this->string($arguments['cwd'] ?? null) !== null) {
            $opts['cwd'] = $this->string($arguments['cwd']);
        } else {
            $opts['workspace'] = $this->workspace(null);
        }

        foreach (['budget', 'code_budget', 'memory_budget', 'feedback_window_hours'] as $key) {
            if (is_numeric($arguments[$key] ?? null)) {
                $opts[$key] = (int) $arguments[$key];
            }
        }

        foreach (['task_type', 'domain', 'flow_id', 'session_id', 'obra_id', 'decision_id'] as $key) {
            $value = $this->string($arguments[$key] ?? null);
            if ($value !== null) {
                $opts[$key] = $value;
            }
        }

        if (is_array($arguments['composed_arc'] ?? null)) {
            $opts['composed_arc'] = $arguments['composed_arc'];
        }

        $changedFiles = $this->stringList($arguments['changed_files'] ?? []);
        if ($changedFiles !== []) {
            $opts['changed_files'] = $changedFiles;
        }

        return $opts;
    }

    // ponytail: primitive normalizers copied verbatim from the façade (which keeps
    // its own pinned copies — architecture scanners token-pin them to the service
    // file). Matches the existing per-Tools-class convention (see MemoryEntryTools).

    /**
     * @return array<int,string>
     */
}
