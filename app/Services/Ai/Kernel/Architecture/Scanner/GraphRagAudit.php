<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class GraphRagAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap683_local_rag_graph_promotion_review' => fn (): array => $this->scanLocalRagGraphPromotionReview(),
            'ap684_external_graph_harness_contract' => fn (): array => $this->scanExternalGraphHarnessContract(),
            'ap685_constelacao_lens1_usage_review_contract' => fn (): array => $this->scanConstelacaoLens1UsageReviewContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanLocalRagGraphPromotionReview(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Context/LocalRagBenchmarkService.php');
        $commandPath = app_path('Console/Commands/AtlasAiLocalRagBenchmarkCommand.php');
        $benchmarkTestPath = base_path('tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apDocPath = base_path('docs/ap/AP-683-local-rag-graph-promotion-review.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $benchmarkTest = File::exists($benchmarkTestPath) ? File::get($benchmarkTestPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'promotion_allowed' => false",
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_promotion_allowed' => false",
            "'supersedes_event_required' => 'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED'",
            "'supersede_authority' => 'human_reviewed_curator_proposal_and_future_ap'",
            'future_graph_rag_python_ap',
            'decision_receipt_for_runtime_promotion',
            'reviewable_policy_patch_with_rollback',
            'rollback_plan_required',
            'policy_patch_review_required',
            'promotionReviewPacket',
            'atlas.local_rag_graph_promotion_review_packet.v1',
            'blocked_until_human_review_and_future_ap',
            'enable_python_graph_rag_runtime',
            "'provider_bypass_allowed' => false",
            "'parallel_memory_allowed' => false",
            "'python_graph_rag_is_candidate_runtime_only' => true",
            'recordLocalRagEvent',
            'raw_context_persisted',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Context/LocalRagBenchmarkService.php: AP-683 Local RAG promotion gate must remain proposal-only and fail-closed [{$token}]";
            }
        }

        foreach ([
            'Run a controlled Local RAG router benchmark before Graph RAG/Python runtime promotion.',
            'evidenceLedgerReport',
            'Ledger evidence',
            'Graph RAG promotion',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLocalRagBenchmarkCommand.php: AP-683 command must keep benchmark evidence explicit [{$token}]";
            }
        }

        foreach ([
            'promotion_gate.promotion_allowed',
            'promotion_gate.graph_rag_promotion_allowed',
            'promotion_gate.python_runtime_promotion_allowed',
            'promotion_gate.supersedes_event_required',
            'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
            'assertStringNotContainsString',
            'raw_context_persistence_allowed',
            'evidence_ledger.status',
            'promotion_evidence_satisfied',
            'promotion_review_contract.review_packet.schema_version',
            'rollback_plan_required',
            'policy_patch_review_required',
            'disable_python_graph_rag_runtime_policy',
            'atlas_ledger_events_table_unavailable_or_write_failed',
        ] as $token) {
            if (! str_contains($benchmarkTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php: AP-683 benchmark contract must be tested [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.local_rag_graph_promotion.v1',
            'atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1',
            'proposal_only',
            'blocked_until_evidence_persisted',
            'open_reviewable_graph_rag_promotion_proposal',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'local_rag_promotion_requires_persisted_evidence_ledger',
            'promotion_evidence_satisfied',
            'requires_future_ap',
            'requires_decision_receipt',
            'requires_rollback_plan',
            "'review_packet' => data_get(\$report, 'promotion_review_contract.review_packet')",
            "'auto_apply' => false",
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-683 Self-Improvement finding must stay proposal-only [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.local_rag_graph_promotion.v1',
            'atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1',
            'open_reviewable_graph_rag_promotion_proposal',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'metadata.policy_patch_candidate.status',
            'metadata.policy_patch_candidate.auto_apply',
            'metadata.policy_patch_candidate.requires_future_ap',
            'metadata.policy_patch_candidate.requires_decision_receipt',
            'metadata.policy_patch_candidate.requires_rollback_plan',
            'metadata.review_signal.review_packet.schema_version',
            'metadata.review_signal.review_packet.required_human_decision',
            'metadata.review_signal.review_packet.forbidden_until_review',
            'metadata.benchmark.evidence_ledger.promotion_evidence_satisfied',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-683 Self-Improvement finding must be tested [{$token}]";
            }
        }

        foreach ([
            'atlas.local_rag_graph_promotion_review.v1',
            'proposal_gate_scaffold',
            'benchmark verde nao autoriza Graph RAG',
            '`promotion_gate.promotion_allowed` deve ser sempre `false`',
            '`evidence_ledger.promotion_evidence_satisfied=true`',
            'Graph RAG nao pode criar Memory Core, Ledger ou Context Builder paralelo',
            '`LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` so pode ser superseded',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'human_reviewed_curator_proposal_and_future_ap',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-683-local-rag-graph-promotion-review.md: AP-683 authority doc must preserve promotion review doctrine [{$token}]";
            }
        }

        foreach ([
            'AP-683 Local RAG promotion review',
            '`LOCAL_RAG_GRAPH_PROMOTION_BLOCKED`',
            'proposal_only',
            'cerebro Python paralelo',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-683 matrix row/conflict must expose fail-closed status [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanExternalGraphHarnessContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php');
        $commandPath = app_path('Console/Commands/AtlasAiExternalGraphHarnessCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiExternalGraphHarnessCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiExternalGraphHarnessApiTest.php');
        $serviceTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasExternalGraphHarnessServiceTest.php');
        $operationsTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $apDocPath = base_path('docs/ap/AP-684-graphify-external-graph-harness.md');
        $ownerDocPath = base_path('docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $serviceTest = File::exists($serviceTestPath) ? File::get($serviceTestPath) : '';
        $operationsTest = File::exists($operationsTestPath) ? File::get($operationsTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $ownerDoc = File::exists($ownerDocPath) ? File::get($ownerDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            'atlas.external_graph_harness.contract.v1',
            'implemented_read_only_contract',
            'candidate_validation_no_runtime_no_writes',
            'atlas.external_graph_candidate.v1',
            'provider_calls_enabled',
            'writes_memory_registry',
            'writes_context_builder',
            'writes_constelacao',
            'changes_decide_routing',
            'installs_graphify_hooks',
            'review_only_constraints',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'forbidden_until_review',
            'surface_direct_external_graph_call',
            'auto_promotion_allowed',
            'blocked_runtime_targets',
            'requires_ap_683_or_successor_for_graph_rag_promotion',
            'promotion_allowed',
            'validateForbiddenCandidateKeys',
            'forbiddenCandidateKeys',
            'memory_write',
            'context_builder_payload',
            'provider_prompt',
            'node_{$index}_duplicate_id',
            'graph_json_to_memory',
            'graph_json_to_context_builder',
            'graph_json_to_constelacao',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php: AP-684 external graph harness must stay read-only/fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:external-graph-harness',
            '--candidate-file',
            'validate graph candidates without writes',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiExternalGraphHarnessCommand.php: AP-684 command contract must expose read-only candidate validation [{$token}]";
            }
        }

        foreach ([
            'test_command_outputs_read_only_contract',
            'test_command_validates_candidate_file_without_writes',
            'accepted_read_only_candidate',
            'writes_constelacao',
            'review_packet',
            'required_human_decision',
            'rollback_plan_required',
            'forbidden_until_review',
            'auto_promotion_allowed',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiExternalGraphHarnessCommandTest.php: AP-684 command behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'test_api_exposes_read_only_external_graph_contract',
            'test_api_validates_candidate_preview_without_operational_writes',
            'writes_memory_registry',
            'writes_context_builder',
            'writes_constelacao',
            'changes_decide_routing',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'auto_promotion_allowed',
            'test_api_rejects_malformed_candidate_payload_fail_closed',
            'candidate_must_be_json_object',
            'submit_external_graph_candidate_as_json_object',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiExternalGraphHarnessApiTest.php: AP-684 API behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'test_contract_is_fail_closed_and_read_only',
            'test_accepts_valid_candidate_as_read_only',
            'test_rejects_private_or_unreferenced_candidate',
            'test_rejects_non_graphify_or_pre_promoted_candidates',
            'test_rejects_nested_authority_fields_and_duplicate_nodes',
            'promotion_target_not_allowed_before_review',
            'forbidden_candidate_key:nodes.2.metadata.provider_prompt',
            'forbidden_candidate_key:edges.0.metadata.context_builder_payload',
            'node_2_duplicate_id',
            'provider_prompt_injection',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'surface_direct_external_graph_call',
            'python_graph_rag_runtime',
        ] as $token) {
            if (! str_contains($serviceTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasExternalGraphHarnessServiceTest.php: AP-684 service gates must be tested [{$token}]";
            }
        }

        foreach ([
            'external_graph_harness_report',
            'code_intelligence_report',
            '/ai/external-graph-harness',
        ] as $token) {
            if (! str_contains($operationsTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-684 Architecture Operations discovery must be tested [{$token}]";
            }
        }

        foreach ([
            'status: implemented_partial',
            'P0 e P2 read-only estao implementados',
            'nao escrever Memory Registry',
            'nao injetar Context Builder',
            'nao criar estrelas de Constelacao',
            'promotion_allowed=false',
            'review_packet',
            'auto_promotion_allowed',
            'qualquer promocao para Graph RAG exige AP-683',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-684-graphify-external-graph-harness.md: AP-684 doc must preserve implemented_partial/read-only authority [{$token}]";
            }
        }

        foreach ([
            'external_graph_candidate.v1',
            'Architecture Operations review',
            'O grafo externo nunca pula para Memory, Context Builder, Constelacao ou Decide.',
            'promotion_target',
            'atlas.external_graph_review_packet.v1',
        ] as $token) {
            if (! str_contains($ownerDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md: AP-684 owner doc must preserve pipeline and promotion boundaries [{$token}]";
            }
        }

        foreach ([
            'External Graph Harness / Graphify AP-684',
            'implemented_partial',
            'review_only_constraints',
            'nao rodar Graphify direto em memoria/docs privados',
            'nao promover Graphify para memoria/contexto/runtime sem AP futuro',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-684 matrix row must preserve partial/read-only status [{$token}]";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return array<int,string>
     */
    private function scanConstelacaoLens1UsageReviewContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Surface/ConstelacaoPositionsService.php');
        // Pin relocated under GOD-DEBULK D3 (2026-07-23): constelacaoUsageReviewFindings moved
        // verbatim from AtlasSelfImprovementRuntime into the Runtime/ConstelacaoUsageReviewSection
        // family class; the AP-685 proposal-only invariant is unchanged, only the file moved
        // (the facade keeps a same-signature delegator).
        $constelacaoSectionPath = app_path('Services/Ai/SelfImprovement/Runtime/ConstelacaoUsageReviewSection.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-constelacao-surface.md');
        $apDocPath = base_path('docs/ap/AP-685-constelacao-lens1-usage-review.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $constelacaoSection = File::exists($constelacaoSectionPath) ? File::get($constelacaoSectionPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $doc = File::exists($docPath) ? File::get($docPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'allowed_lenses' => ['bilderatlas']",
            'command_sky_requires_future_ap_human_review_and_decision_receipt',
            'unsupported_lens_requires_future_ap_human_review_and_decision_receipt',
            'lensGateReason',
            "'lens_role' => 'contemplative_serendipity'",
            "'operational_chrome_allowed' => false",
            "'raw_reading_allowed' => false",
            "'raw_content_allowed' => false",
            "'graph_rag_status' => 'future_governed'",
            "'provider_bypass_allowed' => false",
            "'parallel_memory_allowed' => false",
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_allowed' => false",
            "'lens2_promotion_allowed' => false",
            "'command_sky_allowed' => false",
            "'lineage_allowed' => false",
            "'graph_rag_positioning_allowed' => false",
            "'observation_window_days_required' => 30",
            "'promotion_allowed' => false",
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            "'requires_curator_usage_review' => true",
            'atlas.constelacao.lens1_usage_review.v1',
            'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
            "'rollback_plan_required' => true",
            "'policy_patch_review_required' => true",
            "'forbidden_until_review' => [",
            "'enable_command_sky'",
            "'keep_graph_rag_positioning_disabled'",
            "'auto_promotion_allowed' => false",
            "'blocked_targets' => [",
            "'graph_rag_positioning'",
            "'python_graph_rag_runtime'",
            'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
            'lens1_must_prove_contemplative_value_before_operational_or_graph_promotion',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Surface/ConstelacaoPositionsService.php: AP-685 Constelacao Lente 1 must remain contemplative/fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.constelacao_usage_review.v1',
            'Revisar uso real da Constelacao Lente 1',
            'review_constelacao_lens1_usage_after_observation_window',
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            'Graph RAG/Python segue bloqueado',
            'CONSTELACAO_POSITIONS_SERVED',
            'constelacao_lens1_usage_requires_human_review_before_lens2',
            'atlas.constelacao.lens1_usage_review.v1',
            'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
            "'rollback_plan_required' => true",
            "'policy_patch_review_required' => true",
            "'forbidden_until_review' => [",
            "'enable_command_sky'",
            "'keep_graph_rag_positioning_disabled'",
            "'promotion_allowed' => false",
            "'auto_promotion_allowed' => false",
            "'python_graph_rag_runtime'",
            'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_allowed' => false",
        ] as $token) {
            if (! str_contains($constelacaoSection, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/Runtime/ConstelacaoUsageReviewSection.php: AP-685 Curator finding must review usage without promoting runtime [{$token}]";
            }
        }

        foreach ([
            'test_positions_endpoint_returns_governed_constelacao_payload_without_raw_content',
            "assertJsonPath('lens_maturity_gate.lens2_promotion_allowed', false)",
            "assertJsonPath('lens_maturity_gate.command_sky_allowed', false)",
            "assertJsonPath('lens_maturity_gate.graph_rag_positioning_allowed', false)",
            "assertJsonPath('lens_maturity_gate.promotion_allowed', false)",
            "assertJsonPath('lens1_usage_review_contract.schema_version', 'atlas.constelacao.lens1_usage_review.v1')",
            "assertJsonPath('lens1_usage_review_contract.required_human_decision', 'approve_or_reject_constelacao_lens1_promotion_after_usage_review')",
            "assertJsonPath('lens1_usage_review_contract.rollback_plan_required', true)",
            "assertJsonPath('lens1_usage_review_contract.policy_patch_review_required', true)",
            "assertJsonPath('lens1_usage_review_contract.promotion_allowed', false)",
            "assertJsonPath('lens1_usage_review_contract.auto_promotion_allowed', false)",
            "assertJsonPath('ui_contract.lens_role', 'contemplative_serendipity')",
            "assertJsonPath('ui_contract.operational_chrome_allowed', false)",
            "assertJsonPath('position_engine.graph_rag_status', 'future_governed')",
            "assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.graph_rag_promotion_allowed', false)",
            "assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.python_runtime_allowed', false)",
            'test_unknown_lens_request_is_sanitized_preserved_and_blocked',
            "assertJsonPath('requested_lens', 'graph-rag-admin')",
            "assertJsonPath('lens_gate.reason', 'unsupported_lens_requires_future_ap_human_review_and_decision_receipt')",
            'assertStringNotContainsString',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php: AP-685 API must prove Lente 1 privacy and promotion gates [{$token}]";
            }
        }

        foreach ([
            'test_docs_drift_review_emits_constelacao_lens_usage_review_without_graph_promotion',
            'atlas.self_improvement.constelacao_usage_review.v1',
            'metadata.usage_review_contract.schema_version',
            'metadata.usage_review_contract.required_human_decision',
            'metadata.usage_review_contract.rollback_plan_required',
            'metadata.usage_review_contract.forbidden_until_review',
            'review_constelacao_lens1_usage_after_observation_window',
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            'metadata.promotion_gate.promotion_allowed',
            'metadata.promotion_gate.graph_rag_promotion_allowed',
            'metadata.promotion_gate.python_runtime_allowed',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-685 Curator usage review must be tested [{$token}]";
            }
        }

        foreach ([
            'Constelacao e uma surface contemplativa do Atlas',
            'Lente 1 Bilderatlas e sempre o estado default',
            'Command Sky/linhagem so entra por gesto explicito em fase posterior',
            'Graph RAG/Python permanece bloqueado',
            'fallback silencioso e proibido',
            'Curator usage review',
            '30 dias de uso real',
            'atlas.constelacao.lens1_usage_review.v1',
            'promotion_allowed=false',
            'auto-promotion proibida',
            'review humano e Decision Receipt',
            'implemented_partial',
        ] as $token) {
            if (! str_contains($doc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-constelacao-surface.md: AP-685 doc must preserve Lente 1 usage review doctrine [{$token}]";
            }
        }

        foreach ([
            'AP-685 - Constelacao Lens 1 Usage Review',
            'status: implemented_partial',
            'atlas.constelacao.lens1_usage_review.v1',
            'Lens 1 is always `bilderatlas`',
            '`promotion_allowed=false`',
            '`auto_promotion_allowed=false`',
            '`command_sky_allowed=false`',
            '`graph_rag_positioning_allowed=false`',
            'Silent fallback is not',
            'Any Graph RAG promotion must pass AP-683',
            'CONSTELACAO_POSITIONS_SERVED',
            '30-day observation window',
            'Do not build Command Sky here',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-685-constelacao-lens1-usage-review.md: AP-685 canonical AP doc must exist and preserve Lente 1 usage review contract [{$token}]";
            }
        }

        foreach ([
            'Constelacao Lente 1 usage review',
            'zero elementos operacionais na Lente 1',
            'Coletar uso real por 30 dias',
            'manter Graph RAG/Command Sky bloqueados ate AP/review',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-685 matrix safe-next row must preserve Lente 1 gate [{$token}]";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }
}
