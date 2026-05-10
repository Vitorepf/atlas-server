<?php

namespace App\Services\Ai\Kernel\Architecture;

class AtlasRuntimeLanguageBoundaryReportService
{
    public function __construct(
        private readonly KernelArchitectureStaticScanner $scanner,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $scan = (array) data_get($this->scanner->complianceReport(), 'ap201_runtime_language_boundary_contract', []);
        $valid = (bool) ($scan['valid'] ?? false);
        $violations = array_values((array) ($scan['violations'] ?? []));

        return [
            'schema_version' => 'atlas.runtime_language_boundary_report.v1',
            'status' => $valid ? 'ok' : 'failed',
            'boundary' => [
                'valid' => $valid,
                'violation_count' => count($violations),
                'violations' => $violations,
            ],
            'doctrine' => [
                'laravel' => 'kernel_maestro_decides_and_governs',
                'python_ai_data' => 'rag_ml_embeddings_multimodal_analytics_only_behind_decision_receipt',
                'go_edge' => 'network_ingestion_streaming_concurrency_only_behind_decision_receipt',
                'swift_native_mac' => 'apple_native_context_security_voice_edge_only_behind_decision_receipt',
            ],
            'protected_scopes' => [
                'app/Services/Ai/Context',
                'app/Services/Ai/Cognitive',
                'app/Services/Ai/Domain',
                'app/Services/Ai/Memory',
                'app/Services/Ai/Provider',
                'app/Services/Ai/Surface',
                'app/Services/Semantic',
                'app/Http/Controllers',
                'app/Jobs',
                'app/Console/Commands',
            ],
            'owner_docs' => [
                'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                'docs/engineering-knowledge-base/kernel/static-scans.md',
                'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
            ],
            'forbidden_without_runtime_ap' => [
                'FAISS/Chroma/LlamaIndex/LangGraph/NetworkX in Laravel app',
                'Pandas/Polars/NumPy/scikit/torch/tensorflow/transformers in Laravel app',
                'GraphRag/VectorRag/reranker/clustering engines in Laravel app',
                'direct go run/go build/NATS/Kafka edge runtime shortcuts inside Laravel app',
                'direct Swift compiler or Apple native framework shortcuts inside Laravel app',
            ],
            'preflight_gate' => [
                'schema_version' => 'atlas.runtime_boundary_preflight_gate.v1',
                'required_before_runtime_work' => [
                    'run_feature_placement_strict',
                    'run_runtime_boundary_scan',
                    'update_owner_doc_before_code',
                    'declare_runtime_invocation_contract',
                    'declare_evidence_contract',
                    'declare_rollback_plan',
                    'add_focused_runtime_boundary_tests',
                ],
                'forbidden_preflight_shortcuts' => [
                    'create_runtime_service_without_owner_doc',
                    'call_python_go_or_swift_directly_from_surface',
                    'skip_decision_receipt_for_runtime',
                    'write_memory_context_or_policy_from_runtime',
                    'let_runtime_choose_provider_model_domain_or_flow',
                ],
                'commands' => [
                    'php artisan atlas:ai:place-feature "<feature>" --strict --json',
                    'php artisan atlas:ai:runtime-boundary --json',
                    'php artisan atlas:ai:architecture-validate --json',
                ],
            ],
            'runtime_promotion_policy' => [
                'schema_version' => 'atlas.runtime_promotion_policy.v1',
                'auto_promotion_allowed' => false,
                'human_review_required' => true,
                'decision_receipt_required' => true,
                'rollback_plan_required' => true,
                'evidence_ledger_required' => true,
                'policy_patch_review_required' => true,
                'promotion_allowed_only_after' => [
                    'owner_ap_or_doc_approved',
                    'runtime_invocation_contract_green',
                    'focused_tests_green',
                    'architecture_validate_green',
                    'docs_health_green',
                ],
            ],
            'runtime_invocation_contract' => $this->invocationContract(),
            'runtime_owner_map' => [
                'python_ai_data' => [
                    'allowed_for' => ['rag', 'embeddings', 'rerank', 'graph', 'ml', 'analytics', 'multimodal_processing'],
                    'owner_docs' => [
                        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                        'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
                    ],
                    'allowed_write_scope' => 'runtimes/python',
                ],
                'go_edge' => [
                    'allowed_for' => ['network_ingestion', 'streaming', 'webhooks', 'postbacks', 'high_concurrency_daemons'],
                    'owner_docs' => [
                        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                    ],
                    'allowed_write_scope' => 'runtimes/go',
                ],
                'swift_native_mac' => [
                    'allowed_for' => ['keychain', 'touch_id', 'screen_capture', 'fsevents', 'accessibility', 'core_ml_edge'],
                    'owner_docs' => [
                        'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                        'docs/engineering-knowledge-base/atlas-native-mac-agent.md',
                    ],
                    'allowed_write_scope' => 'runtimes/swift',
                ],
            ],
            'surfaces' => [
                'cli' => 'php artisan atlas:ai:runtime-boundary --json',
                'api' => '/ai/runtime-boundary',
                'mcp' => 'atlas_runtime_boundary',
            ],
            'writes' => false,
            'next_action' => $valid
                ? 'runtime_boundary_clear_continue_with_feature_placement_before_runtime_work'
                : 'fix_runtime_boundary_violations_before_adding_runtime_or_rag_code',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function invocationContract(): array
    {
        return [
            'schema_version' => 'atlas.runtime_invocation_contract.v1',
            'kernel_first' => true,
            'required_fields' => [
                'envelope_id',
                'decision_receipt_hash',
                'runtime_family',
                'capability',
                'mode',
                'limits',
                'privacy_class',
                'evidence_sink',
            ],
            'allowed_runtime_families' => [
                'python_ai_data',
                'go_edge',
                'swift_native_mac',
            ],
            'forbidden_runtime_authority' => [
                'choose_provider_or_model',
                'choose_domain_or_flow',
                'mutate_policy',
                'write_memory_directly',
                'bypass_evidence_ledger',
                'create_parallel_context_store',
            ],
            'return_contract' => [
                'schema_version',
                'envelope_id',
                'decision_receipt_hash',
                'status',
                'artifacts',
                'metrics',
                'evidence_refs',
                'errors',
            ],
        ];
    }
}
