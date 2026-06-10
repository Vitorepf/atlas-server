<?php

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Facades\DB;

final class LocalRagReadinessService
{
    public const SCHEMA_VERSION = 'atlas.local_rag_readiness.v1';

    public function __construct(private readonly ContextRetrievalRouter $router) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $driver = DB::getDriverName();
        $semanticNotes = DatabaseTableAvailability::has('semantic_notes');
        $attachments = DatabaseTableAvailability::has('ai_attachment_index_entries');
        $semanticEmbedding = DatabaseTableAvailability::hasColumn('semantic_notes', 'embedding');
        $attachmentEmbedding = DatabaseTableAvailability::hasColumn('ai_attachment_index_entries', 'embedding');
        $embeddingProvider = (string) config('atlas.semantic_memory.embedding_provider', 'semantic_rag');
        $localDefault = $embeddingProvider === 'semantic_rag';
        $realProviderConfigured = in_array($embeddingProvider, ['semantic_rag', 'openai'], true);
        $retrievalPlan = $this->router->plan(
            'local rag readiness graph retrieval vector semantic open brain',
            AiTaskRequest::fromInput('local rag readiness graph retrieval vector semantic open brain', [
                'source_type' => 'system',
                'payload' => [
                    'task_type' => 'research',
                    'atlas_workflow_mode' => 'research',
                    'domain' => 'atlas',
                    'risk_level' => 'low',
                ],
            ], ['agent' => 'orquestrador', 'intent' => 'local_rag_readiness']),
            [],
            ['include_memory_registry' => true],
        );

        $gates = [
            'semantic_notes_table' => $semanticNotes,
            'semantic_embedding_column' => $semanticEmbedding,
            'attachment_index_table' => $attachments,
            'attachment_embedding_column' => $attachmentEmbedding,
            'local_default_embedding' => $localDefault,
            'real_embedding_provider_configured' => $realProviderConfigured,
            'hash_fallback_retired' => true,
            'context_router_available' => true,
            'vector_retrieval_governed' => $this->sourceAvailable($retrievalPlan, 'vector_retrieval'),
            'graph_retrieval_future_governed' => $this->sourceStatus($retrievalPlan, 'graph_retrieval') === 'future_governed',
            'provider_bypass_allowed' => false,
            'parallel_memory_allowed' => false,
        ];

        $blocking = collect([
            'semantic_notes_table',
            'semantic_embedding_column',
            'local_default_embedding',
            'context_router_available',
            'vector_retrieval_governed',
        ])->filter(fn (string $gate): bool => ! (bool) $gates[$gate])->values()->all();

        $attention = collect([
            'attachment_index_table',
            'attachment_embedding_column',
        ])->filter(fn (string $gate): bool => ! (bool) $gates[$gate])->values()->all();

        $status = $blocking !== [] ? 'blocked' : ($attention !== [] ? 'degraded' : 'ready');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'runtime_family' => 'laravel_kernel_now_python_ai_data_future',
            'driver' => $driver,
            'embedding' => [
                'provider' => $embeddingProvider,
                'model' => (string) config('atlas.semantic_memory.embedding_model', 'text-embedding-3-small'),
                'dimensions' => (int) config('atlas.semantic_memory.embedding_dimensions', 1536),
                'local_default' => $localDefault,
                'hash_fallback_retired' => true,
                'external_embedding_requires_privacy_review' => $embeddingProvider === 'openai',
            ],
            'stores' => [
                'semantic_notes' => [
                    'table_exists' => $semanticNotes,
                    'embedding_column_exists' => $semanticEmbedding,
                    'vector_search_native' => $driver === 'pgsql' && $semanticEmbedding,
                ],
                'ai_attachment_index_entries' => [
                    'table_exists' => $attachments,
                    'embedding_column_exists' => $attachmentEmbedding,
                    'vector_search_native' => $driver === 'pgsql' && $attachmentEmbedding,
                ],
            ],
            'retrieval_router' => [
                'schema_version' => $retrievalPlan['schema_version'],
                'mode' => $retrievalPlan['mode'],
                'readiness' => $retrievalPlan['readiness'],
                'selected_sources' => collect($retrievalPlan['selected_sources'])
                    ->map(fn (array $source): array => [
                        'type' => $source['type'],
                        'status' => $source['status'],
                        'available' => $source['available'],
                        'runtime' => $source['runtime'],
                        'provider_bypass_allowed' => $source['provider_bypass_allowed'],
                        'owner_doc' => $source['owner_doc'],
                    ])
                    ->values()
                    ->all(),
            ],
            'gates' => $gates,
            'blocking_gates' => $blocking,
            'attention_gates' => $attention,
            'guardrails' => [
                'kernel_decides' => true,
                'provider_bypass_allowed' => false,
                'runtime_must_not_execute_without_decision_receipt' => true,
                'python_graph_rag_is_future_runtime_not_parallel_brain' => true,
                'open_brain_consumes_provider_safe_context_only' => true,
            ],
            'owner_docs' => [
                'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
                'docs/engineering-knowledge-base/evolution/context-builder-roadmap.md',
                'docs/engineering-knowledge-base/open-brain-context-injection.md',
                'docs/engineering-knowledge-base/memory-core-maturity-dod.md',
            ],
            'next_action' => match ($status) {
                'ready' => 'run_controlled_local_rag_benchmark_before_promoting_python_graph_rag',
                'degraded' => 'index_attachment_store_or_accept_semantic_notes_only_scope',
                default => 'restore_semantic_memory_tables_and_semantic_rag_embedding_before_local_rag_work',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function sourceAvailable(array $plan, string $type): bool
    {
        $source = collect($plan['selected_sources'] ?? [])
            ->first(fn (array $source): bool => $source['type'] === $type);

        return is_array($source) && (bool) ($source['available'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function sourceStatus(array $plan, string $type): ?string
    {
        $source = collect($plan['selected_sources'] ?? [])
            ->first(fn (array $source): bool => $source['type'] === $type);

        return is_array($source) ? (string) ($source['status'] ?? '') : null;
    }
}
