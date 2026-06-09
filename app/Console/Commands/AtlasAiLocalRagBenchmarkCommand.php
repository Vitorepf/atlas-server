<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AtlasAiLocalRagBenchmarkCommand extends Command
{
    protected $signature = 'atlas:ai:local-rag-benchmark
        {--record-memory-quality : Persist a Memory Quality snapshot with benchmark metadata}
        {--workspace= : Workspace path for the Memory Quality snapshot}
        {--schedule-plan : Print the governed recurring benchmark schedule plan}
        {--rivals-report : Compare recent retrieval benchmark snapshots without running benchmark or taking action}
        {--rivals-shadow-case-contract : Print the deterministic hash-only AP-693 shadow case contract without executing rival strategies}
        {--rivals-shadow-plan : Print the blocked shadow-comparison plan for retrieval rival strategies}
        {--emit-rivals-shadow-inbox : Emit a proposal Inbox item for human review of the blocked Rivals shadow plan}
        {--emit-external-vector-rag-preflight-inbox : Emit a proposal Inbox item for human review of the blocked external vector/RAG preflight}
        {--emit-rivals-inbox : Emit a proposal Inbox item when the Rivals report finds a retrieval regression}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run a controlled Local RAG router benchmark before Graph RAG/Python runtime promotion.';

    public function handle(LocalRagBenchmarkService $benchmark, AtlasMemoryQualityService $quality, ProposalInboxEmitter $inbox): int
    {
        if ((bool) $this->option('schedule-plan')) {
            return $this->renderSchedulePlan();
        }

        if ((bool) $this->option('rivals-shadow-case-contract')) {
            return $this->renderRivalsShadowCaseContract();
        }

        if ((bool) $this->option('rivals-shadow-plan') || (bool) $this->option('emit-rivals-shadow-inbox')) {
            return $this->renderRivalsShadowPlan($inbox);
        }

        if ((bool) $this->option('rivals-report')) {
            return $this->renderRivalsReport($quality, $inbox);
        }

        $payload = $benchmark->report();
        $payload['evidence_ledger'] = $benchmark->evidenceLedgerReport($payload);
        $payload['memory_quality_snapshot'] = $this->recordMemoryQualitySnapshot($quality, $payload);
        $payload['retrieval_benchmark_history'] = $this->retrievalBenchmarkHistory($quality);
        $payload['emitted_external_vector_rag_preflight_inbox_item'] = $this->emitExternalRetrievalPreflightInbox($payload, $inbox);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Local RAG Benchmark</>', $payload['status']);
        $this->components->twoColumnDetail('Cases', $payload['passed_case_count'].'/'.$payload['case_count']);
        $this->components->twoColumnDetail('Average score', (string) $payload['average_score']);
        $this->components->twoColumnDetail('Quality corpus', (string) data_get($payload, 'quality_corpus.status'));
        $this->components->twoColumnDetail(
            'Independent precision corpus',
            (string) data_get($payload, 'independent_precision_corpus.status')
                .' (R@'.(string) data_get($payload, 'independent_precision_corpus.primary_k', '?').'='
                .(data_get($payload, 'independent_precision_corpus.measured') === true
                    ? (string) data_get($payload, 'independent_precision_corpus.metrics.recall_at_primary_k')
                    : 'unmeasured')
                .')',
        );
        $this->components->twoColumnDetail('Graph RAG promotion', data_get($payload, 'promotion_gate.graph_rag_promotion_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Promotion review', (string) data_get($payload, 'promotion_review_contract.status'));
        $this->components->twoColumnDetail('Auto promotion', data_get($payload, 'promotion_review_contract.auto_promotion_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Ledger evidence', (string) data_get($payload, 'evidence_ledger.status'));
        $this->components->twoColumnDetail('Memory quality snapshot', (string) data_get($payload, 'memory_quality_snapshot.status', 'not_recorded'));
        $this->components->twoColumnDetail('Retrieval benchmark history', (string) data_get($payload, 'retrieval_benchmark_history.summary.total', 0));
        $this->components->twoColumnDetail('Remaining prereqs', implode(', ', data_get($payload, 'promotion_gate.remaining_prerequisites', [])));
        $this->components->twoColumnDetail('Next action', $payload['next_action']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function emitExternalRetrievalPreflightInbox(array $payload, ProposalInboxEmitter $inbox): ?array
    {
        if (! (bool) $this->option('emit-external-vector-rag-preflight-inbox')) {
            return null;
        }

        $contract = (array) data_get($payload, 'promotion_review_contract.external_vector_rag_preflight_contract', []);
        if ($contract === []) {
            return [
                'status' => 'not_emitted',
                'reason' => 'external_vector_rag_preflight_contract_missing',
            ];
        }

        $item = $inbox->emit([
            'title' => 'Revisar preflight de external vector/RAG Memory/Open Brain',
            'category' => 'memory_quality',
            'source_type' => 'local_rag_benchmark',
            'source_id' => data_get($contract, 'preflight_hash'),
            'dedupe_key' => 'memory-open-brain-external-vector-rag-preflight:'.sha1((string) data_get($contract, 'preflight_hash', 'unknown')),
            'problem' => 'External vector/RAG e importante para Constelacao e Open Brain, mas ainda esta bloqueado antes de embeddings, store vetorial externo ou runtime Python.',
            'solution' => 'Revisar o contrato de preflight, confirmar gates, evidencias e rollback; registrar Decision Receipt sem executar runtime, provider, embeddings ou policy patch.',
            'worth_it' => 'Este review transforma uma pendencia critica dos modulos 1/2 em trilha governada sem contaminar Voice nem criar memoria paralela.',
            'metadata' => [
                'schema_version' => 'atlas.external_vector_rag.preflight_inbox.v1',
                'review_signal' => [
                    'status' => 'review_required',
                    'severity' => 'high',
                    'recommended_action' => 'review_external_vector_rag_preflight',
                    'reasons' => [
                        'external_vector_rag_preflight_declared',
                        'human_review_required',
                        'decision_receipt_required',
                        'embedding_generation_blocked',
                    ],
                ],
            ],
            'payload' => [
                'external_vector_rag_preflight_contract' => [
                    'schema_version' => data_get($contract, 'schema_version'),
                    'status' => data_get($contract, 'status'),
                    'mode' => data_get($contract, 'mode'),
                    'model_ownership' => data_get($contract, 'model_ownership', []),
                    'runtime_family' => data_get($contract, 'runtime_family'),
                    'candidate_capability_id' => data_get($contract, 'candidate_capability_id'),
                    'architecture_operation_id' => data_get($contract, 'architecture_operation_id'),
                    'execution_gate' => data_get($contract, 'execution_gate', []),
                    'required_before_any_embedding_or_external_rag' => data_get($contract, 'required_before_any_embedding_or_external_rag', []),
                    'review_packet' => data_get($contract, 'review_packet', []),
                    'lineage' => data_get($contract, 'lineage', []),
                    'preflight_hash' => data_get($contract, 'preflight_hash'),
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                    'raw_capture_exposed' => false,
                ],
            ],
            'source_refs' => [
                ['type' => 'ap', 'id' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md'],
                ['type' => 'ap', 'id' => 'docs/ap/AP-684-graphify-external-graph-harness.md'],
                ['type' => 'engineering_knowledge', 'id' => 'docs/engineering-knowledge-base/memory/retrieval-and-context.md'],
            ],
            'available_actions' => [
                ['id' => 'review_external_vector_rag_preflight', 'label' => 'Revisar preflight', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ],
            'confidence' => 0.88,
        ]);

        if ($item === null) {
            return [
                'status' => 'not_emitted',
                'reason' => 'inbox_tables_unavailable',
            ];
        }

        return [
            'status' => 'emitted',
            'id' => $item->id,
            'title' => $item->title,
            'review_signal' => data_get($item->payload ?? [], 'proposal_contract.review_signal'),
        ];
    }

    private function renderRivalsShadowCaseContract(): int
    {
        $payload = self::rivalsShadowCaseContract();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Retrieval Rivals Shadow Case Contract</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Case count', (string) $payload['case_count']);
        $this->components->twoColumnDetail('Execution', data_get($payload, 'execution_gate.shadow_execution_allowed_now') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Contract hash', (string) $payload['case_contract_hash']);
        $this->components->twoColumnDetail('Next action', (string) $payload['next_action']);

        return self::SUCCESS;
    }

    private function renderRivalsShadowPlan(ProposalInboxEmitter $inbox): int
    {
        $payload = self::rivalsShadowPlan();
        $payload['emitted_inbox_item'] = $this->emitRetrievalRivalsShadowInbox($payload, $inbox);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Retrieval Rivals Shadow Plan</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Baseline', (string) $payload['baseline_strategy_id']);
        $this->components->twoColumnDetail('Execution', data_get($payload, 'execution_gate.runtime_execution_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Required gates', implode(', ', data_get($payload, 'execution_gate.required_before_any_shadow_run', [])));
        $this->components->twoColumnDetail('Next action', (string) $payload['next_action']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function emitRetrievalRivalsShadowInbox(array $payload, ProposalInboxEmitter $inbox): ?array
    {
        if (! (bool) $this->option('emit-rivals-shadow-inbox')) {
            return null;
        }

        $caseContract = self::rivalsShadowCaseContract();

        $item = $inbox->emit([
            'title' => 'Revisar escopo shadow de retrieval Memory/Open Brain',
            'category' => 'memory_quality',
            'source_type' => 'local_rag_benchmark',
            'source_id' => null,
            'dedupe_key' => 'memory-retrieval-rivals-shadow:'.sha1((string) ($payload['plan_hash'] ?? 'unknown')),
            'problem' => 'O plano AP-693 para comparar estrategias de retrieval em shadow mode precisa de revisao humana antes de qualquer execucao.',
            'solution' => 'Revisar candidatos, gates, evidencias e proibicoes; aprovar, rejeitar ou pedir mais evidencia sem executar runtime ou policy patch.',
            'worth_it' => 'A comparacao shadow pode melhorar Memory/Open Brain, mas so deve nascer com provider-safety, audit trail e rollback claros.',
            'metadata' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_inbox.v1',
                'review_signal' => [
                    'status' => 'review_required',
                    'severity' => 'medium',
                    'recommended_action' => 'review_retrieval_shadow_scope',
                    'reasons' => [
                        'shadow_case_contract_declared_review_required',
                        'human_review_required',
                        'runtime_invocation_contracts_declared_blocked',
                    ],
                ],
            ],
            'payload' => [
                'retrieval_rivals_shadow_plan' => [
                    'schema_version' => $payload['schema_version'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'mode' => $payload['mode'] ?? null,
                    'safety' => $payload['safety'] ?? [],
                    'review_ap' => $payload['review_ap'] ?? null,
                    'plan_hash' => $payload['plan_hash'] ?? null,
                    'baseline_strategy_id' => $payload['baseline_strategy_id'] ?? null,
                    'execution_gate' => $payload['execution_gate'] ?? [],
                    'review_packet' => $payload['review_packet'] ?? [],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                ],
                'retrieval_rivals_shadow_case_contract' => [
                    'schema_version' => $caseContract['schema_version'] ?? null,
                    'status' => $caseContract['status'] ?? null,
                    'mode' => $caseContract['mode'] ?? null,
                    'review_ap' => $caseContract['review_ap'] ?? null,
                    'plan_hash' => $caseContract['plan_hash'] ?? null,
                    'case_contract_hash' => $caseContract['case_contract_hash'] ?? null,
                    'strategy_contract_hash' => $caseContract['strategy_contract_hash'] ?? null,
                    'case_count' => $caseContract['case_count'] ?? null,
                    'safety' => $caseContract['safety'] ?? [],
                    'evidence_contract' => $caseContract['evidence_contract'] ?? [],
                    'evidence_ledger_event_contract' => $caseContract['evidence_ledger_event_contract'] ?? [],
                    'rollback_plan' => $caseContract['rollback_plan'] ?? [],
                    'runtime_invocation_contracts' => $caseContract['runtime_invocation_contracts'] ?? [],
                    'execution_gate' => $caseContract['execution_gate'] ?? [],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                    'raw_capture_exposed' => false,
                ],
            ],
            'source_refs' => [
                [
                    'type' => 'ap',
                    'id' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                ],
                [
                    'type' => 'engineering_knowledge',
                    'id' => 'docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md',
                ],
            ],
            'available_actions' => [
                ['id' => 'review_retrieval_shadow_scope', 'label' => 'Revisar escopo', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ],
            'confidence' => 0.84,
        ]);

        if ($item === null) {
            return [
                'status' => 'not_emitted',
                'reason' => 'inbox_tables_unavailable',
            ];
        }

        return [
            'status' => 'emitted',
            'id' => $item->id,
            'title' => $item->title,
            'review_signal' => data_get($item->payload ?? [], 'proposal_contract.review_signal'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function rivalsShadowPlan(): array
    {
        $payload = [
            'schema_version' => 'atlas.local_rag_benchmark.rivals_shadow_plan.v1',
            'status' => 'blocked',
            'mode' => 'proposal_only_no_runtime_execution',
            'baseline_strategy_id' => 'current_governed_hybrid_memory_recall',
            'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
            'proposal_only' => true,
            'auto_action_allowed' => false,
            'provider_call_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_auto_apply_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'strategy_candidates' => [
                [
                    'id' => 'current_governed_hybrid_memory_recall',
                    'role' => 'baseline',
                    'runtime_family' => 'laravel_kernel',
                    'execution_status' => 'already_measured_by_local_rag_benchmark',
                    'shadow_execution_allowed_now' => false,
                    'promotion_allowed_without_review' => false,
                    'evidence_required' => [
                        'memory_recall_corpus_metrics',
                        'memory_recall_golden_set_hashes',
                        'retrieval_benchmark_history',
                    ],
                ],
                [
                    'id' => 'lexical_keyword_fallback_candidate',
                    'role' => 'rival_candidate',
                    'runtime_family' => 'laravel_kernel',
                    'execution_status' => 'blocked_until_shadow_case_contract',
                    'shadow_execution_allowed_now' => false,
                    'promotion_allowed_without_review' => false,
                    'evidence_required' => [
                        'deterministic_case_contract',
                        'side_by_side_metric_delta',
                        'evidence_ledger_event_contract',
                        'human_reviewed_policy_patch',
                    ],
                ],
                [
                    'id' => 'future_graph_rag_python_candidate',
                    'role' => 'rival_candidate',
                    'runtime_family' => 'python_ai_data',
                    'execution_status' => 'blocked_until_future_ap_and_runtime_invocation_contract',
                    'shadow_execution_allowed_now' => false,
                    'promotion_allowed_without_review' => false,
                    'evidence_required' => [
                        'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                        'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                        'runtime_invocation_contract',
                        'decision_receipt_hash',
                        'rollback_plan',
                    ],
                ],
            ],
            'execution_gate' => [
                'status' => 'blocked',
                'runtime_execution_allowed' => false,
                'provider_call_allowed' => false,
                'required_before_any_shadow_run' => [
                    'human_review',
                    'shadow_case_contract',
                    'decision_receipt_hash',
                    'evidence_ledger_event_contract',
                    'privacy_provider_safety_review',
                    'rollback_plan',
                ],
                'blocked_reasons' => [
                    'no_human_review',
                    'no_shadow_case_contract',
                    'no_decision_receipt',
                    'no_runtime_invocation_contract_for_alternatives',
                ],
            ],
            'review_packet' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1',
                'status' => 'human_review_required',
                'required_human_decision' => 'approve_or_reject_retrieval_shadow_comparison_scope',
                'evidence_required' => [
                    'latest_local_rag_benchmark_snapshot',
                    'memory_recall_golden_set_hashes',
                    'candidate_strategy_contracts',
                    'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                    'provider_safety_checklist',
                ],
                'forbidden_actions' => [
                    'execute_python_graph_rag',
                    'run_unreviewed_lexical_rival',
                    'persist_raw_query',
                    'persist_raw_context',
                    'send_raw_capture_to_provider',
                    'auto_apply_policy_patch',
                    'choose_provider_or_model',
                    'promote_rival_strategy',
                ],
            ],
            'evaluation_contract' => [
                'schema_version' => 'atlas.rivals.evaluation_contract.v1',
                'benchmark_family' => 'memory_open_brain_retrieval',
                'baseline_strategy_id' => 'current_governed_hybrid_memory_recall',
                'candidate_strategy_count' => 2,
                'required_metrics' => [
                    'precision_at_k',
                    'missed_critical_context',
                    'context_contamination_rate',
                    'latency_ms',
                    'provider_safe_context_rate',
                ],
                'winner_policy' => 'human_reviewed_multi_metric_delta_only',
                'minimum_evidence_before_claim' => [
                    'golden_set_hashes',
                    'case_contract_hash',
                    'side_by_side_results_hash',
                    'rollback_plan',
                ],
                'forbidden_claims_without_evidence' => [
                    'rival_strategy_is_better',
                    'graph_rag_ready_for_promotion',
                    'provider_context_quality_improved',
                ],
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
                'auto_promotion_allowed' => false,
            ],
            'next_action' => 'draft_ap_for_retrieval_shadow_comparison_before_execution',
            'generated_at' => now()->toJSON(),
        ];
        $payload['safety'] = self::retrievalRivalsSafety([
            'proposal_only' => true,
            'provider_call_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_auto_apply_allowed' => false,
            'memory_write_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
        ]);
        $payload['plan_hash_algorithm'] = 'sha256';
        $payload['plan_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public static function rivalsShadowCaseContract(): array
    {
        $shadowPlan = self::rivalsShadowPlan();
        $shadowPlanScope = $shadowPlan;
        unset($shadowPlanScope['generated_at'], $shadowPlanScope['plan_hash'], $shadowPlanScope['plan_hash_algorithm']);

        $semanticContract = [
            'schema_version' => 'atlas.memory_retrieval_rivals_shadow_case_contract.v1',
            'status' => 'declared_blocked',
            'mode' => 'case_contract_only_no_runtime_execution',
            'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
            'plan_hash' => hash('sha256', json_encode($shadowPlanScope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'baseline_strategy_id' => 'current_governed_hybrid_memory_recall',
            'case_count' => 3,
            'case_inputs' => [
                [
                    'case_id' => 'memory-open-brain-core-recall',
                    'query_hash' => hash('sha256', 'memory-open-brain-core-recall'),
                    'expected_evidence_hashes_required' => true,
                    'expected_source_types' => ['memory_registry', 'verbatim_memory', 'engineering_knowledge'],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                ],
                [
                    'case_id' => 'capture-to-curation-lineage',
                    'query_hash' => hash('sha256', 'capture-to-curation-lineage'),
                    'expected_evidence_hashes_required' => true,
                    'expected_source_types' => ['capture', 'semantic_curation', 'memory_delta'],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                ],
                [
                    'case_id' => 'external-graph-rag-promotion-boundary',
                    'query_hash' => hash('sha256', 'external-graph-rag-promotion-boundary'),
                    'expected_evidence_hashes_required' => true,
                    'expected_source_types' => ['ap', 'architecture_operation', 'runtime_boundary'],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                ],
            ],
            'strategy_contracts' => [
                [
                    'id' => 'current_governed_hybrid_memory_recall',
                    'runtime_family' => 'laravel_kernel',
                    'execution_allowed_now' => false,
                    'requires_runtime_invocation_contract' => false,
                    'provider_call_allowed' => false,
                    'memory_write_allowed' => false,
                    'raw_text_allowed' => false,
                ],
                [
                    'id' => 'lexical_keyword_fallback_candidate',
                    'runtime_family' => 'laravel_kernel',
                    'execution_allowed_now' => false,
                    'requires_runtime_invocation_contract' => true,
                    'provider_call_allowed' => false,
                    'memory_write_allowed' => false,
                    'raw_text_allowed' => false,
                ],
                [
                    'id' => 'future_graph_rag_python_candidate',
                    'runtime_family' => 'python_ai_data',
                    'execution_allowed_now' => false,
                    'requires_runtime_invocation_contract' => true,
                    'provider_call_allowed' => false,
                    'memory_write_allowed' => false,
                    'raw_text_allowed' => false,
                ],
            ],
            'metric_contract' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_metrics.v1',
                'required_metrics' => [
                    'precision_at_3',
                    'precision_at_5',
                    'missed_critical_context_count',
                    'context_contamination_count',
                    'provider_safe_violation_count',
                    'latency_ms',
                    'provider_safe_context_rate',
                ],
                'comparison_policy' => 'human_reviewed_multi_metric_delta_only',
                'minimum_case_count_before_claim' => 3,
                'delta_claim_allowed_now' => false,
                'winner_claim_allowed_now' => false,
            ],
            'evidence_contract' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_evidence.v1',
                'required_hashes' => [
                    'case_contract_hash',
                    'plan_hash',
                    'strategy_contract_hash',
                    'golden_set_hashes',
                    'side_by_side_results_hash',
                    'decision_receipt_hash',
                ],
                'ledger_event_required' => true,
                'raw_query_allowed' => false,
                'raw_context_allowed' => false,
                'raw_capture_allowed' => false,
            ],
            'evidence_ledger_event_contract' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_ledger_event_contract.v1',
                'status' => 'declared_no_events_recorded',
                'event_family' => 'RETRIEVAL_RIVALS_SHADOW_*',
                'event_types' => [
                    'RETRIEVAL_RIVALS_SHADOW_CASE_DECLARED',
                    'RETRIEVAL_RIVALS_SHADOW_RUN_BLOCKED',
                    'RETRIEVAL_RIVALS_SHADOW_RESULTS_REVIEWED',
                ],
                'payload_rules' => [
                    'case_contract_hash_required' => true,
                    'strategy_contract_hash_required' => true,
                    'decision_receipt_hash_required_before_execution' => true,
                    'side_by_side_results_hash_required_after_execution' => true,
                    'raw_query_allowed' => false,
                    'raw_context_allowed' => false,
                    'raw_capture_allowed' => false,
                ],
                'record_event_allowed_now' => false,
            ],
            'rollback_plan' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_rollback_plan.v1',
                'status' => 'declared',
                'baseline_strategy_id' => 'current_governed_hybrid_memory_recall',
                'rollback_target' => 'current_governed_hybrid_memory_recall_only',
                'automatic_rollback_allowed' => false,
                'policy_auto_apply_allowed' => false,
                'memory_write_allowed' => false,
                'operator_actions' => [
                    'disable_shadow_strategy_candidate',
                    'keep_current_governed_hybrid_memory_recall',
                    'record_rollback_decision_receipt',
                    'append_evidence_ledger_hash_only_event',
                ],
            ],
            'runtime_invocation_contracts' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_shadow_runtime_invocation_contracts.v1',
                'status' => 'declared_blocked',
                'kernel_first' => true,
                'candidate_contracts' => [
                    [
                        'strategy_id' => 'lexical_keyword_fallback_candidate',
                        'contract_family' => 'kernel_internal_strategy_contract',
                        'runtime_family' => 'laravel_kernel',
                        'execution_allowed_now' => false,
                        'requires_external_runtime' => false,
                        'required_fields' => [
                            'case_contract_hash',
                            'strategy_contract_hash',
                            'decision_receipt_hash',
                            'limits',
                            'evidence_sink',
                        ],
                    ],
                    [
                        'strategy_id' => 'future_graph_rag_python_candidate',
                        'contract_family' => 'atlas.runtime_invocation_contract.v1',
                        'runtime_family' => 'python_ai_data',
                        'capability_id' => 'memory_open_brain.retrieval_shadow.graph_rag_candidate',
                        'execution_allowed_now' => false,
                        'requires_external_runtime' => true,
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
                    ],
                ],
                'forbidden_runtime_authority' => [
                    'choose_provider_or_model',
                    'choose_domain_or_flow',
                    'mutate_policy',
                    'write_memory_directly',
                    'persist_raw_query_or_context',
                    'create_parallel_context_store',
                ],
            ],
            'execution_gate' => [
                'status' => 'blocked',
                'shadow_case_contract_declared' => true,
                'evidence_ledger_event_contract_declared' => true,
                'rollback_plan_declared' => true,
                'runtime_invocation_contracts_declared' => true,
                'shadow_execution_allowed_now' => false,
                'provider_call_allowed' => false,
                'runtime_execution_allowed' => false,
                'policy_auto_apply_allowed' => false,
                'memory_write_allowed' => false,
                'remaining_before_shadow_run' => [
                    'human_review',
                    'decision_receipt_hash',
                    'privacy_provider_safety_review',
                ],
            ],
            'forbidden_actions' => [
                'execute_python_graph_rag',
                'run_unreviewed_lexical_rival',
                'persist_raw_query',
                'persist_raw_context',
                'send_raw_capture_to_provider',
                'auto_apply_policy_patch',
                'choose_provider_or_model',
                'promote_rival_strategy',
            ],
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
            'next_action' => 'open_ap_693_human_review_for_shadow_case_contract_before_any_execution',
        ];
        $semanticContract['strategy_contract_hash'] = hash('sha256', json_encode($semanticContract['strategy_contracts'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $semanticContract['case_contract_hash_algorithm'] = 'sha256';
        $semanticContract['case_contract_hash'] = hash('sha256', json_encode($semanticContract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $semanticContract['safety'] = self::retrievalRivalsSafety([
            'proposal_only' => true,
            'provider_call_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_auto_apply_allowed' => false,
            'memory_write_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
            'shadow_case_contract_declared' => true,
        ]);
        $semanticContract['generated_at'] = now()->toJSON();

        return $semanticContract;
    }

    private function renderRivalsReport(AtlasMemoryQualityService $quality, ProposalInboxEmitter $inbox): int
    {
        $payload = $this->retrievalRivalsReport($quality);
        $payload['emitted_inbox_item'] = $this->emitRetrievalRivalsInbox($payload, $inbox);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Retrieval Rivals</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Outcome', (string) data_get($payload, 'comparison.outcome', 'none'));
        $this->components->twoColumnDetail('Latest score', (string) data_get($payload, 'comparison.latest.score', '-'));
        $this->components->twoColumnDetail('Previous score', (string) data_get($payload, 'comparison.previous.score', '-'));
        $this->components->twoColumnDetail('Score delta', (string) data_get($payload, 'comparison.score_delta', '-'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($payload, 'review_signal.severity', 'none'));
        $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'review_signal.recommended_action', 'record_more_snapshots'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function emitRetrievalRivalsInbox(array $payload, ProposalInboxEmitter $inbox): ?array
    {
        if (! (bool) $this->option('emit-rivals-inbox')) {
            return null;
        }

        if (($payload['status'] ?? null) !== 'attention') {
            return [
                'status' => 'skipped',
                'reason' => 'retrieval_rivals_report_not_attention',
            ];
        }

        $item = $inbox->emit([
            'title' => 'Revisar regressao de retrieval Memory/Open Brain',
            'category' => 'memory_quality',
            'source_type' => 'local_rag_benchmark',
            'source_id' => data_get($payload, 'comparison.latest.id'),
            'dedupe_key' => 'memory-retrieval-rivals:'.sha1((string) data_get($payload, 'comparison.latest.id', 'unknown')),
            'problem' => 'O benchmark longitudinal de retrieval detectou regressao no Memory/Open Brain.',
            'solution' => 'Revisar snapshots, metricas e review packet antes de ajustar memoria, ranking ou runtime.',
            'worth_it' => 'A regressao afeta a qualidade do contexto exportado para providers e deve permanecer proposal-only ate revisao humana.',
            'metadata' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_inbox.v1',
                'review_signal' => $payload['review_signal'] ?? [],
            ],
            'payload' => [
                'retrieval_rivals' => [
                    'schema_version' => $payload['schema_version'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'safety' => $payload['safety'] ?? [],
                    'report_hash' => $payload['report_hash'] ?? null,
                    'comparison' => $payload['comparison'] ?? [],
                    'review_packet' => $payload['review_packet'] ?? [],
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                ],
            ],
            'source_refs' => [
                [
                    'type' => 'memory_quality_snapshot',
                    'id' => (string) data_get($payload, 'comparison.latest.id', ''),
                ],
                [
                    'type' => 'memory_quality_snapshot',
                    'id' => (string) data_get($payload, 'comparison.previous.id', ''),
                ],
            ],
            'available_actions' => [
                ['id' => 'review_retrieval_regression', 'label' => 'Revisar regressao', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ],
            'confidence' => 0.86,
        ]);

        if ($item === null) {
            return [
                'status' => 'not_emitted',
                'reason' => 'inbox_tables_unavailable',
            ];
        }

        return [
            'status' => 'emitted',
            'id' => $item->id,
            'title' => $item->title,
            'review_signal' => data_get($item->payload ?? [], 'proposal_contract.review_signal', data_get($item->payload ?? [], 'retrieval_rivals.review_signal')),
        ];
    }

    private function renderSchedulePlan(): int
    {
        $payload = self::schedulePlan();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Local RAG Benchmark Schedule</>', $payload['enabled'] ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Registration', (string) data_get($payload, 'scheduler_registration.status'));
        $this->components->twoColumnDetail('Command', (string) $payload['command']);
        $this->components->twoColumnDetail('Time', (string) $payload['time']);
        $this->components->twoColumnDetail('Timezone', (string) $payload['timezone']);
        $this->components->twoColumnDetail('Next run', (string) ($payload['next_run_at'] ?? '-'));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    public static function schedulePlan(): array
    {
        $time = trim((string) config('atlas_ai.local_rag_benchmark.schedule_time', '02:30'));
        $timezone = trim((string) config('app.timezone', 'UTC'));
        $enabled = (bool) config('atlas_ai.local_rag_benchmark.schedule_enabled', false);
        $validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
        $validTimezone = in_array($timezone, timezone_identifiers_list(), true);
        $schedulable = $enabled && $validTime && $validTimezone;
        $issues = array_values(array_filter([
            $enabled ? null : 'local_rag_benchmark_schedule_disabled',
            $validTime ? null : 'invalid_local_rag_benchmark_schedule_time',
            $validTimezone ? null : 'invalid_local_rag_benchmark_schedule_timezone',
        ]));

        $payload = [
            'schema_version' => 'atlas.local_rag_benchmark.schedule.v1',
            'status' => 'ok',
            'enabled' => $enabled,
            'schedulable' => $schedulable,
            'command' => self::scheduledCommand(),
            'time' => $time,
            'timezone' => $timezone,
            'cadence' => 'daily',
            'next_run_at' => $schedulable ? self::nextRunAt($time, $timezone) : null,
            'scheduler_registration' => [
                'status' => $schedulable ? 'registered' : 'skipped',
                'registered_command_count' => $schedulable ? 1 : 0,
                'skipped_reason' => $schedulable ? null : ($issues[0] ?? 'not_schedulable'),
            ],
            'health' => [
                'status' => $issues === [] ? 'healthy' : ($enabled ? 'warning' : 'disabled'),
                'issues' => $issues,
                'actions' => $enabled ? [] : ['set ATLAS_AI_LOCAL_RAG_BENCHMARK_SCHEDULE_ENABLED=true'],
            ],
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
        ];
        $payload['plan_hash_algorithm'] = 'sha256';
        $payload['plan_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    public static function scheduledCommand(): string
    {
        $command = 'atlas:ai:local-rag-benchmark --record-memory-quality --json';
        $workspace = config('atlas_ai.local_rag_benchmark.schedule_workspace');

        if (is_string($workspace) && trim($workspace) !== '') {
            $command .= ' --workspace='.escapeshellarg(trim($workspace));
        }

        return $command;
    }

    private static function nextRunAt(string $time, string $timezone): ?string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now = CarbonImmutable::now($timezone);
        $next = $now->setTime($hour, $minute);

        if ($next->lessThanOrEqualTo($now)) {
            $next = $next->addDay();
        }

        return $next->toJSON();
    }

    /**
     * @param  array<string,mixed>  $benchmarkPayload
     * @return array<string,mixed>|null
     */
    private function recordMemoryQualitySnapshot(AtlasMemoryQualityService $quality, array $benchmarkPayload): ?array
    {
        if (! (bool) $this->option('record-memory-quality')) {
            return null;
        }

        $workspace = $this->stringOption('workspace');
        $scorecard = $quality->scorecard($workspace ? ['workspace' => $workspace] : []);
        $snapshot = $quality->recordSnapshot($scorecard, [
            'workspace' => $workspace,
            'source_type' => 'local_rag_benchmark',
            'source_id' => 'local_rag_benchmark:'.hash('sha256', implode('|', [
                (string) data_get($benchmarkPayload, 'quality_corpus.corpus_id', 'unknown'),
                (string) data_get($benchmarkPayload, 'memory_recall_corpus.schema_version', 'unknown'),
                (string) data_get($benchmarkPayload, 'memory_recall_corpus.case_count', 0),
            ])),
            'metadata' => [
                'command' => 'atlas:ai:local-rag-benchmark',
                'benchmark_schema_version' => (string) ($benchmarkPayload['schema_version'] ?? self::class),
                'benchmark_status' => (string) ($benchmarkPayload['status'] ?? 'unknown'),
                'quality_corpus' => [
                    'schema_version' => data_get($benchmarkPayload, 'quality_corpus.schema_version'),
                    'status' => data_get($benchmarkPayload, 'quality_corpus.status'),
                    'metrics' => data_get($benchmarkPayload, 'quality_corpus.metrics', []),
                    'checks' => data_get($benchmarkPayload, 'quality_corpus.checks', []),
                ],
                'memory_recall_corpus' => [
                    'schema_version' => data_get($benchmarkPayload, 'memory_recall_corpus.schema_version'),
                    'status' => data_get($benchmarkPayload, 'memory_recall_corpus.status'),
                    'case_count' => data_get($benchmarkPayload, 'memory_recall_corpus.case_count'),
                    'metrics' => data_get($benchmarkPayload, 'memory_recall_corpus.metrics', []),
                    'checks' => data_get($benchmarkPayload, 'memory_recall_corpus.checks', []),
                    'golden_set' => [
                        'schema_version' => data_get($benchmarkPayload, 'memory_recall_corpus.golden_set.schema_version'),
                        'case_count' => data_get($benchmarkPayload, 'memory_recall_corpus.golden_set.case_count'),
                        'raw_query_persisted' => data_get($benchmarkPayload, 'memory_recall_corpus.golden_set.raw_query_persisted'),
                        'raw_context_persisted' => data_get($benchmarkPayload, 'memory_recall_corpus.golden_set.raw_context_persisted'),
                    ],
                ],
                'retrieval_rivals_packet' => [
                    'schema_version' => data_get($benchmarkPayload, 'retrieval_rivals_packet.schema_version'),
                    'status' => data_get($benchmarkPayload, 'retrieval_rivals_packet.status'),
                    'mode' => data_get($benchmarkPayload, 'retrieval_rivals_packet.mode'),
                    'baseline_strategy_id' => data_get($benchmarkPayload, 'retrieval_rivals_packet.baseline_strategy_id'),
                    'case_count' => data_get($benchmarkPayload, 'retrieval_rivals_packet.case_count'),
                    'raw_query_persisted' => data_get($benchmarkPayload, 'retrieval_rivals_packet.raw_query_persisted'),
                    'raw_context_persisted' => data_get($benchmarkPayload, 'retrieval_rivals_packet.raw_context_persisted'),
                    'comparison' => [
                        'winner' => data_get($benchmarkPayload, 'retrieval_rivals_packet.comparison.winner'),
                        'delta_measured' => data_get($benchmarkPayload, 'retrieval_rivals_packet.comparison.delta_measured'),
                    ],
                    'strategy_statuses' => collect((array) data_get($benchmarkPayload, 'retrieval_rivals_packet.strategies', []))
                        ->map(fn (mixed $strategy): array => [
                            'id' => is_array($strategy) ? ($strategy['id'] ?? null) : null,
                            'status' => is_array($strategy) ? ($strategy['status'] ?? null) : null,
                            'runtime_family' => is_array($strategy) ? ($strategy['runtime_family'] ?? null) : null,
                            'promotion_allowed' => is_array($strategy) ? ($strategy['promotion_allowed'] ?? null) : null,
                        ])
                        ->filter(fn (array $strategy): bool => is_string($strategy['id'] ?? null) && $strategy['id'] !== '')
                        ->values()
                        ->all(),
                    'checks' => data_get($benchmarkPayload, 'retrieval_rivals_packet.checks', []),
                ],
                'evidence_ledger' => [
                    'status' => data_get($benchmarkPayload, 'evidence_ledger.status'),
                    'recorded_event_count' => data_get($benchmarkPayload, 'evidence_ledger.recorded_event_count'),
                    'payload_hashes' => collect((array) data_get($benchmarkPayload, 'evidence_ledger.events', []))
                        ->pluck('payload_hash')
                        ->filter()
                        ->values()
                        ->all(),
                ],
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
            ],
        ]);

        return $snapshot ? $quality->snapshotPayload($snapshot) : [
            'status' => 'not_recorded',
            'missing_reason' => 'atlas_memory_quality_snapshots_table_unavailable',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function retrievalBenchmarkHistory(AtlasMemoryQualityService $quality): ?array
    {
        if (! (bool) $this->option('record-memory-quality')) {
            return null;
        }

        return $quality->history([
            'workspace' => $this->stringOption('workspace'),
            'source_type' => 'local_rag_benchmark',
        ], 30, 8);
    }

    /**
     * @return array<string,mixed>
     */
    private function retrievalRivalsReport(AtlasMemoryQualityService $quality): array
    {
        $history = $quality->history([
            'workspace' => $this->stringOption('workspace'),
            'source_type' => 'local_rag_benchmark',
        ], 30, 12);
        $snapshots = collect((array) ($history['snapshots'] ?? []))
            ->filter(fn (mixed $snapshot): bool => is_array($snapshot))
            ->sortBy(fn (array $snapshot): string => (string) ($snapshot['snapshot_at'] ?? $snapshot['created_at'] ?? ''))
            ->values();
        $latest = $snapshots->last();
        $previous = $snapshots->count() >= 2 ? $snapshots->get($snapshots->count() - 2) : null;
        $status = (string) ($history['status'] ?? 'unknown');

        if ($status === 'snapshot_table_missing') {
            return $this->retrievalRivalsBasePayload($history, 'unavailable', null, null, [
                'severity' => 'medium',
                'reasons' => ['memory_quality_snapshot_table_missing'],
                'recommended_action' => 'run_migrations_before_retrieval_rivals',
            ]);
        }

        if (! is_array($latest) || ! is_array($previous)) {
            return $this->retrievalRivalsBasePayload($history, 'insufficient_history', $latest, $previous, [
                'severity' => 'medium',
                'reasons' => ['need_at_least_two_local_rag_benchmark_snapshots'],
                'recommended_action' => 'record_two_memory_quality_benchmark_snapshots',
            ]);
        }

        $latestScore = (int) ($latest['score'] ?? 0);
        $previousScore = (int) ($previous['score'] ?? 0);
        $scoreDelta = $latestScore - $previousScore;
        $latestMetrics = (array) data_get($latest, 'metadata.memory_recall_corpus.metrics', []);
        $previousMetrics = (array) data_get($previous, 'metadata.memory_recall_corpus.metrics', []);
        $metricDeltas = $this->retrievalMetricDeltas($latestMetrics, $previousMetrics);
        $regressionReasons = $this->retrievalRegressionReasons($latest, $latestMetrics, $scoreDelta, $metricDeltas);
        $outcome = match (true) {
            $regressionReasons !== [] => 'regressed',
            $scoreDelta >= 5 => 'improved',
            default => 'stable',
        };

        return $this->retrievalRivalsBasePayload($history, $outcome === 'regressed' ? 'attention' : 'ready', $latest, $previous, [
            'severity' => $outcome === 'regressed' ? 'high' : 'low',
            'reasons' => $regressionReasons ?: [$outcome === 'improved' ? 'retrieval_quality_improved' : 'retrieval_quality_stable'],
            'recommended_action' => $outcome === 'regressed'
                ? 'open_memory_retrieval_regression_review'
                : 'continue_scheduled_retrieval_snapshots',
        ], [
            'outcome' => $outcome,
            'score_delta' => $scoreDelta,
            'metric_deltas' => $metricDeltas,
        ]);
    }

    /**
     * @param  array<string,mixed>  $history
     * @param  array<string,mixed>|null  $latest
     * @param  array<string,mixed>|null  $previous
     * @param  array<string,mixed>  $reviewSignal
     * @param  array<string,mixed>  $comparisonOverrides
     * @return array<string,mixed>
     */
    private function retrievalRivalsBasePayload(array $history, string $status, ?array $latest, ?array $previous, array $reviewSignal, array $comparisonOverrides = []): array
    {
        $comparison = array_merge([
            'outcome' => $status,
            'latest' => $this->retrievalRivalsSnapshotSummary($latest),
            'previous' => $this->retrievalRivalsSnapshotSummary($previous),
            'score_delta' => null,
            'metric_deltas' => [],
        ], $comparisonOverrides);
        $payload = [
            'schema_version' => 'atlas.local_rag_benchmark.rivals_report.v1',
            'status' => $status,
            'source_type' => 'local_rag_benchmark',
            'proposal_only' => true,
            'auto_action_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'workspace_hash' => $latest['workspace_hash'] ?? $previous['workspace_hash'] ?? null,
            'history_summary' => $history['summary'] ?? [],
            'comparison' => $comparison,
            'review_signal' => [
                'status' => $status === 'attention' ? 'review_required' : ($status === 'ready' ? 'monitor' : 'needs_more_evidence'),
                'severity' => (string) ($reviewSignal['severity'] ?? 'medium'),
                'reasons' => array_values((array) ($reviewSignal['reasons'] ?? [])),
                'recommended_action' => (string) ($reviewSignal['recommended_action'] ?? 'record_more_snapshots'),
            ],
            'review_packet' => [
                'schema_version' => 'atlas.memory_retrieval_rivals_review_packet.v1',
                'status' => 'proposal_only',
                'human_review_required' => $status === 'attention',
                'evidence_required' => [
                    'latest_local_rag_benchmark_snapshot',
                    'previous_local_rag_benchmark_snapshot',
                    'memory_recall_corpus_metrics',
                    'golden_set_hash_counts',
                ],
                'forbidden_actions' => [
                    'auto_promote_graph_rag',
                    'enable_python_runtime',
                    'persist_raw_query',
                    'persist_raw_context',
                    'send_raw_capture_to_provider',
                ],
            ],
            'generated_at' => now()->toJSON(),
        ];
        $payload['safety'] = self::retrievalRivalsSafety([
            'proposal_only' => true,
            'provider_call_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_auto_apply_allowed' => false,
            'memory_write_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
            'latest_snapshot_hashed' => is_array($comparison['latest'] ?? null) && ($comparison['latest']['source_id_hash'] ?? null) !== null,
            'previous_snapshot_hashed' => is_array($comparison['previous'] ?? null) && ($comparison['previous']['source_id_hash'] ?? null) !== null,
        ]);
        $payload['report_hash_algorithm'] = 'sha256';
        $payload['report_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private static function retrievalRivalsSafety(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.retrieval_rivals.safety.v1',
            'proposal_only' => true,
            'provider_call_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_auto_apply_allowed' => false,
            'memory_write_allowed' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     * @return array<string,mixed>|null
     */
    private function retrievalRivalsSnapshotSummary(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'id' => $snapshot['id'] ?? null,
            'source_id_hash' => is_string($snapshot['source_id'] ?? null) ? hash('sha256', (string) $snapshot['source_id']) : null,
            'status' => $snapshot['status'] ?? null,
            'score' => $snapshot['score'] ?? null,
            'benchmark_status' => data_get($snapshot, 'metadata.benchmark_status'),
            'memory_recall_status' => data_get($snapshot, 'metadata.memory_recall_corpus.status'),
            'memory_recall_case_count' => data_get($snapshot, 'metadata.memory_recall_corpus.case_count'),
            'golden_set_case_count' => data_get($snapshot, 'metadata.memory_recall_corpus.golden_set.case_count'),
            'retrieval_rivals_schema_version' => data_get($snapshot, 'metadata.retrieval_rivals_packet.schema_version'),
            'retrieval_rivals_status' => data_get($snapshot, 'metadata.retrieval_rivals_packet.status'),
            'retrieval_rivals_mode' => data_get($snapshot, 'metadata.retrieval_rivals_packet.mode'),
            'retrieval_rivals_winner' => data_get($snapshot, 'metadata.retrieval_rivals_packet.comparison.winner'),
            'retrieval_rivals_delta_measured' => data_get($snapshot, 'metadata.retrieval_rivals_packet.comparison.delta_measured'),
            'metrics' => data_get($snapshot, 'metadata.memory_recall_corpus.metrics', []),
            'snapshot_at' => $snapshot['snapshot_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $latest
     * @param  array<string,mixed>  $previous
     * @return array<string,float|int>
     */
    private function retrievalMetricDeltas(array $latest, array $previous): array
    {
        $keys = [
            'precision_at_3',
            'precision_at_5',
            'missed_critical_context_count',
            'context_contamination_count',
            'provider_safe_violation_count',
            'stale_context_use_count',
            'budget_truncation_count',
            'reason_coverage',
        ];

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [
                $key => round((float) ($latest[$key] ?? 0) - (float) ($previous[$key] ?? 0), 4),
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $latest
     * @param  array<string,mixed>  $latestMetrics
     * @param  array<string,float|int>  $metricDeltas
     * @return array<int,string>
     */
    private function retrievalRegressionReasons(array $latest, array $latestMetrics, int $scoreDelta, array $metricDeltas): array
    {
        $reasons = [];

        if ($scoreDelta <= -5) {
            $reasons[] = 'memory_quality_score_regressed';
        }

        if ((string) data_get($latest, 'metadata.benchmark_status') !== 'passed') {
            $reasons[] = 'benchmark_status_not_passed';
        }

        if ((string) data_get($latest, 'metadata.memory_recall_corpus.status') !== 'passed') {
            $reasons[] = 'memory_recall_corpus_not_passed';
        }

        foreach (['missed_critical_context_count', 'context_contamination_count', 'provider_safe_violation_count', 'stale_context_use_count'] as $key) {
            if ((int) ($latestMetrics[$key] ?? 0) > 0) {
                $reasons[] = $key;
            }
        }

        if ((float) ($latestMetrics['precision_at_3'] ?? 0) < 0.8 || (float) ($latestMetrics['precision_at_5'] ?? 0) < 0.8) {
            $reasons[] = 'retrieval_precision_below_target';
        }

        if ((float) ($latestMetrics['reason_coverage'] ?? 0) < 1.0) {
            $reasons[] = 'reason_coverage_below_target';
        }

        if ((float) ($metricDeltas['precision_at_3'] ?? 0) < -0.1 || (float) ($metricDeltas['reason_coverage'] ?? 0) < -0.1) {
            $reasons[] = 'retrieval_metric_delta_regressed';
        }

        return array_values(array_unique($reasons));
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
