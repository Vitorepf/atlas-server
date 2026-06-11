<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use Tests\TestCase;

class AtlasArchitectureOperationsCatalogTest extends TestCase
{
    public function test_catalog_exposes_canonical_architecture_operations(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);
        $summary = $catalog->summary();

        $this->assertSame('arquitetura_mae', $catalog->sectionKey());
        $this->assertSame('atlas.architecture_operations.v1', $summary['schema_version']);
        $this->assertSame('arquitetura_mae', $summary['section']);
        $this->assertSame(104, $summary['command_count']);
        $this->assertSame($catalog->commands(), $summary['commands']);
        $this->assertSame([
            'architecture_operations',
            'architecture_validate',
            'architecture_readiness',
            'documentation_health',
            'session_bootstrap',
            'feature_placement',
            'documentation_split_plan',
            'documentation_reality_score',
            'documentation_reality_acceptance_matrix',
            'documentation_enforcement',
            'code_reality_anti_duplicate',
            'code_reality_reality_audit',
            'code_reality_global_duplication_audit',
            'code_reality_status_drift_audit',
            'code_reality_reachability',
            'code_reality_deletion_preflight',
            'universal_reality_cartography_navigation_slice',
            'universal_reality_cartography_visual_scene',
            'universal_reality_cartography_human_clarity',
            'software_twin_quality_score',
            'software_twin_impact',
            'software_twin_snapshot',
            'verified_evolution_boundary_contract',
            'verified_evolution_proof_plan',
            'verified_evolution_execution_contract',
            'verified_evolution_scope_drift_watch',
            'verified_evolution_patch_simulation',
            'verified_evolution_outcome_bridge',
            'software_twin_verified_evolution_certification',
            'ap_agent_workflow_registry',
            'provider_projection_status',
            'provider_projection_write',
            'provider_release_review',
            'provider_release_sources',
            'local_rag_readiness',
            'local_rag_benchmark',
            'local_rag_benchmark_schedule',
            'local_rag_benchmark_rivals_report',
            'local_rag_benchmark_rivals_shadow_plan',
            'local_rag_benchmark_rivals_shadow_case_contract',
            'local_rag_benchmark_rivals_shadow_inbox',
            'local_rag_benchmark_rivals_inbox',
            'local_rag_graph_promotion_review',
            'external_vector_rag_preflight_inbox',
            'external_graph_harness_report',
            'knowledge_sync',
            'code_intelligence_index',
            'kernel_slo_report',
            'voice_realtime_contract',
            'voice_realtime_bootstrap',
            'voice_realtime_dependencies',
            'voice_realtime_dependency_install_plan',
            'voice_realtime_scripted_worker_example',
            'voice_realtime_scripted_smoke',
            'voice_realtime_callback_smoke',
            'voice_realtime_callback_sequence_smoke',
            'voice_realtime_callback_loop_check',
            'voice_realtime_preflight',
            'voice_realtime_activation_contract',
            'voice_realtime_sdk_check',
            'voice_realtime_token_issuer_plan',
            'voice_realtime_token_issuer_smoke',
            'voice_realtime_livekit_server_probe',
            'voice_realtime_worker_plan',
            'voice_realtime_production_loop_plan',
            'voice_realtime_product_loop_check',
            'voice_realtime_pre_start_health_checks_smoke',
            'voice_realtime_production_loop_smoke',
            'voice_realtime_worker_start_check',
            'voice_realtime_runtime_certification',
            'voice_realtime_promotion_review_packet',
            'voice_realtime_readiness',
            'voice_realtime_rivals_report',
            'voice_python_runtime_contract_test',
            'kernel_pipeline_report',
            'repair_report',
            'provider_performance_report',
            'agent_behavior_report',
            'dynamic_compute_market_report',
            'provider_performance_curator_review',
            'provider_release_curator_review',
            'agent_behavior_curator_review',
            'voice_realtime_curator_review',
            'provider_cost_rates_missing',
            'provider_cost_rates_upsert',
            'qualitative_levels_report',
            'rivals_strategy_report',
            'rivals_strategy_due_reviews',
            'rivals_strategy_record_review',
            'strategic_decision_review',
            'decision_receipt_report',
            'ledger_replay',
            'ledger_projection_worker',
            'self_improvement_schedule_report',
            'inbox_action_report',
            'capture_inbox_pipeline_report',
            'capture_inbox_pipeline_backfill_contracts',
            'task_orchestration_report',
            'task_orchestration_backfill_receipts',
            'tool_action_runtime_report',
            'long_running_work_report',
            'long_running_work_declare_baseline',
            'proactive_layer_report',
            'runtime_language_boundary',
        ], $summary['operation_ids']);

        $commands = array_column($summary['commands'], 'command');

        $this->assertContains('php artisan atlas:ai:architecture-operations --json', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-validate', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-readiness --json', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('php artisan atlas:ai:session-bootstrap --task="<task>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:place-feature "<feature>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:docs-split-plan --json', $commands);
        $this->assertContains('php artisan atlas:universal-reality-cartography human-clarity --mode=flow --strict --json', $commands);
        $this->assertContains('php artisan atlas:software-twin quality-score --json', $commands);
        $this->assertContains('php artisan atlas:software-twin impact --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:software-twin snapshot --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution boundary-contract --objective="<objective>" --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution proof-plan --objective="<objective>" --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution execution-contract --objective="<objective>" --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution drift-watch --objective="<objective>" --target="<target>" --changed-file="<path>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution patch-simulation --objective="<objective>" --target="<target>" --changed-file="<path>" --json', $commands);
        $this->assertContains('php artisan atlas:verified-evolution outcome-bridge --objective="<objective>" --target="<target>" --evidence="<evidence>" --json', $commands);
        $this->assertContains('php artisan atlas:software-twin-verified-evolution:certify --json --strict', $commands);
        $this->assertContains('php artisan atlas:ai:ap-agent-workflow --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-sources --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-readiness --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --schedule-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-report --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --emit-rivals-shadow-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:external-graph-harness --json', $commands);
        $this->assertContains('atlas engineering knowledge sync --prune --json', $commands);
        $this->assertContains('atlas engineering knowledge index-code --prune --summary-only --json', $commands);
        $this->assertContains('php artisan atlas:ai:slo --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice contract --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice bootstrap --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice dependencies --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice dependency-install-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice scripted-example --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice scripted-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice callback-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice callback-sequence-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice callback-loop-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice preflight --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice activation-contract --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice sdk-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice token-issuer-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice token-issuer-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice livekit-server-probe --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice worker-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice production-loop-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice product-loop-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice production-loop-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice worker-start-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice runtime-certify --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice promotion-review-packet --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice readiness --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice rivals --hours=24 --json', $commands);
        $this->assertContains('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $commands);
        $this->assertContains('php artisan atlas:ai:kernel-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:repair-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-performance --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('php artisan atlas:ai:qualitative-levels --hours=720 --json', $commands);
        $this->assertContains('php artisan atlas:ai:rivals-strategy report --hours=8760 --json', $commands);
        $this->assertContains('php artisan atlas:ai:rivals-strategy due-reviews --due-days=30 --json', $commands);
        $this->assertContains('php artisan atlas:ai:rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json', $commands);
        $this->assertContains('php artisan atlas:ai:strategic-decision review --json', $commands);
        $this->assertContains('php artisan atlas:ai:decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('php artisan atlas:ai:ledger <id> --json', $commands);
        $this->assertContains('php artisan atlas:ai:ledger-project --limit=500 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:inbox-action-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:capture-inbox-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --json', $commands);
        $this->assertContains('php artisan atlas:ai:task-orchestration-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:task-orchestration-backfill-receipts --hours=720 --json', $commands);
        $this->assertContains('php artisan atlas:ai:tool-action-runtime-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:long-running-work-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:proactive-layer-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $commands);
        $this->assertSame('architecture_operations', data_get($summary, 'commands.0.id'));
        $this->assertSame('catalog', data_get($summary, 'commands.0.kind'));
        $this->assertSame('cli', data_get($summary, 'commands.0.surface'));
        $this->assertIsString(data_get($summary, 'commands.10.kind'));
        $commandsById = collect($summary['commands'])->keyBy('id');
        $this->assertSame('provider_evolution', data_get($commandsById, 'provider_release_review.kind'));
        $this->assertSame('provider_evolution', data_get($commandsById, 'provider_release_sources.kind'));
        $this->assertSame('runtime_readiness', data_get($commandsById, 'local_rag_readiness.kind'));
        $this->assertSame('runtime_benchmark', data_get($commandsById, 'local_rag_benchmark.kind'));
        $this->assertSame('runtime_benchmark_schedule', data_get($commandsById, 'local_rag_benchmark_schedule.kind'));
        $this->assertSame('governance_gate', data_get($commandsById, 'software_twin_quality_score.kind'));
        $this->assertSame('governance_gate', data_get($commandsById, 'verified_evolution_proof_plan.kind'));

        $commandsById = collect($summary['commands'])->keyBy('id');
        $this->assertSame('catalog', data_get($commandsById, 'architecture_operations.kind'));
        $this->assertSame('cli', data_get($commandsById, 'architecture_operations.surface'));
        $this->assertSame('json', data_get($commandsById, 'architecture_operations.output'));
        $this->assertSame('readiness', data_get($commandsById, 'architecture_readiness.kind'));
        $this->assertSame('/ai/architecture/readiness', data_get($commandsById, 'architecture_readiness.api_endpoint'));
        $this->assertSame('atlas_architecture_readiness', data_get($commandsById, 'architecture_readiness.mcp_tool'));
        $this->assertSame('bootstrap', data_get($commandsById, 'session_bootstrap.kind'));
        $this->assertSame('/ai/session-bootstrap', data_get($commandsById, 'session_bootstrap.api_endpoint'));
        $this->assertSame('atlas_session_bootstrap', data_get($commandsById, 'session_bootstrap.mcp_tool'));
        $this->assertSame('php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json', data_get($commandsById, 'session_bootstrap.strict_command'));
        $this->assertSame([
            'owner',
            'status',
            'split_required_count',
            'total_split_required_count',
            'execution_order',
            'first_doc',
            'command',
        ], data_get($commandsById, 'session_bootstrap.output_contract.docs_split_plan'));
        $this->assertSame('gate_status=blocked', data_get($commandsById, 'session_bootstrap.strict_gate.blocks_when'));
        $this->assertSame('session_bootstrap_blocked_by_strict_gate', data_get($commandsById, 'session_bootstrap.strict_gate.mcp_error'));
        $this->assertSame('governance_gate', data_get($commandsById, 'feature_placement.kind'));
        $this->assertSame('/ai/feature-placement', data_get($commandsById, 'feature_placement.api_endpoint'));
        $this->assertSame('atlas_feature_placement', data_get($commandsById, 'feature_placement.mcp_tool'));
        $this->assertSame('php artisan atlas:ai:place-feature "<feature>" --strict --json', data_get($commandsById, 'feature_placement.strict_command'));
        $this->assertSame('gate_status=blocked', data_get($commandsById, 'feature_placement.strict_gate.blocks_when'));
        $this->assertSame('feature_placement_blocked_by_strict_gate', data_get($commandsById, 'feature_placement.strict_gate.mcp_error'));
        $this->assertSame('governance_gate', data_get($commandsById, 'documentation_split_plan.kind'));
        $this->assertSame('/ai/docs-split-plan', data_get($commandsById, 'documentation_split_plan.api_endpoint'));
        $this->assertSame('atlas_docs_split_plan', data_get($commandsById, 'documentation_split_plan.mcp_tool'));
        $this->assertSame(['owner', 'severity', 'status'], data_get($commandsById, 'documentation_split_plan.filter_options'));
        $this->assertSame('php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json', data_get($commandsById, 'documentation_split_plan.focused_command'));
        $this->assertSame('governance_gate', data_get($commandsById, 'ap_agent_workflow_registry.kind'));
        $this->assertSame('docs/ap/AP-204-ap-agent-workflow-registry.md', data_get($commandsById, 'ap_agent_workflow_registry.doc'));
        $this->assertSame('provider_projection', data_get($commandsById, 'provider_projection_status.kind'));
        $this->assertSame('provider_projection', data_get($commandsById, 'provider_projection_write.kind'));
        $this->assertSame('provider_evolution', data_get($commandsById, 'provider_release_review.kind'));
        $this->assertSame('runtime_readiness', data_get($commandsById, 'local_rag_readiness.kind'));
        $this->assertSame('runtime_benchmark', data_get($commandsById, 'local_rag_benchmark.kind'));
        $this->assertSame('runtime_benchmark_schedule', data_get($commandsById, 'local_rag_benchmark_schedule.kind'));
        $this->assertSame('docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md', data_get($commandsById, 'local_rag_benchmark_schedule.doc'));
        $this->assertSame('maturity_report', data_get($commandsById, 'local_rag_benchmark_rivals_report.kind'));
        $this->assertSame('docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md', data_get($commandsById, 'local_rag_benchmark_rivals_report.doc'));
        $this->assertSame('governance_gate', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_plan.kind'));
        $this->assertSame('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_plan.doc'));
        $this->assertSame('atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_plan.review_contract'));
        $this->assertSame('governance_gate', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_case_contract.kind'));
        $this->assertSame('atlas.memory_retrieval_rivals_shadow_case_contract.v1', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_case_contract.review_contract'));
        $this->assertSame('review_queue', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_inbox.kind'));
        $this->assertSame('atlas.memory_retrieval_rivals_shadow_inbox.v1', data_get($commandsById, 'local_rag_benchmark_rivals_shadow_inbox.review_contract'));
        $this->assertSame('review_queue', data_get($commandsById, 'local_rag_benchmark_rivals_inbox.kind'));
        $this->assertSame('atlas.memory_retrieval_rivals_inbox.v1', data_get($commandsById, 'local_rag_benchmark_rivals_inbox.review_contract'));
        $this->assertSame('governance_gate', data_get($commandsById, 'local_rag_graph_promotion_review.kind'));
        $this->assertSame('atlas.local_rag_graph_promotion_review.v1', data_get($commandsById, 'local_rag_graph_promotion_review.review_contract'));
        $this->assertSame('review_queue', data_get($commandsById, 'external_vector_rag_preflight_inbox.kind'));
        $this->assertSame('docs/engineering-knowledge-base/memory/retrieval-and-context.md', data_get($commandsById, 'external_vector_rag_preflight_inbox.doc'));
        $this->assertSame('atlas.external_vector_rag.preflight_inbox.v1', data_get($commandsById, 'external_vector_rag_preflight_inbox.review_contract'));
        $this->assertSame('code_intelligence_report', data_get($commandsById, 'external_graph_harness_report.kind'));
        $this->assertSame('/ai/external-graph-harness', data_get($commandsById, 'external_graph_harness_report.api_endpoint'));
        $this->assertSame('maintenance', data_get($commandsById, 'knowledge_sync.kind'));
        $this->assertSame('surface_contract', data_get($commandsById, 'voice_realtime_contract.kind'));
        $this->assertSame('surface_contract', data_get($commandsById, 'voice_realtime_bootstrap.kind'));
        $this->assertSame('/ai/voice/runtime/contract', data_get($commandsById, 'voice_realtime_contract.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/contract', data_get($commandsById, 'voice_realtime_contract.mobile_endpoint'));
        $this->assertSame('/ai/voice/runtime/bootstrap', data_get($commandsById, 'voice_realtime_bootstrap.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/bootstrap', data_get($commandsById, 'voice_realtime_bootstrap.mobile_endpoint'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_dependencies.kind'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', data_get($commandsById, 'voice_realtime_dependencies.doc'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.pre_implementation_gate'));
        $this->assertSame('atlas.voice_realtime.python_runtime_plan.v1', data_get($commandsById, 'voice_realtime_dependencies.runtime_dependency_contract'));
        $this->assertSame('ATLAS_VOICE_PYTHON_BIN', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.environment_variable'));
        $this->assertSame('atlas_ai.voice_realtime.python_binary', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.config_key'));
        $this->assertSame('3.10', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.minimum_version'));
        $this->assertSame('3.11', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.recommended_version'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.operator_managed'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.auto_install'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.fail_closed_when_missing'));
        $this->assertContains('livekit', data_get($commandsById, 'voice_realtime_dependencies.required_for_terms'));
        $this->assertContains('configured_binary', data_get($commandsById, 'voice_realtime_dependencies.output_contract.python_runtime'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_dependency_install_plan.kind'));
        $this->assertSame('/ai/voice/runtime/dependency-install-plan', data_get($commandsById, 'voice_realtime_dependency_install_plan.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependency-install-plan', data_get($commandsById, 'voice_realtime_dependency_install_plan.mobile_endpoint'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependency_install_plan.pre_implementation_gate'));
        $this->assertSame('atlas.voice_realtime.dependency_install_plan.v1', data_get($commandsById, 'voice_realtime_dependency_install_plan.runtime_dependency_contract'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependency_install_plan.operator_managed_policy.auto_install'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependency_install_plan.operator_managed_policy.pip_execution_allowed'));
        $this->assertContains('requirements_sha256', data_get($commandsById, 'voice_realtime_dependency_install_plan.output_contract.required_fields'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_scripted_smoke.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_callback_smoke.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_callback_sequence_smoke.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_callback_loop_check.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_preflight.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_activation_contract.kind'));
        $this->assertSame('runtime_readiness', data_get($commandsById, 'voice_realtime_token_issuer_plan.kind'));
        $this->assertSame('runtime_readiness', data_get($commandsById, 'voice_realtime_token_issuer_smoke.kind'));
        $this->assertSame('runtime_readiness', data_get($commandsById, 'voice_realtime_pre_start_health_checks_smoke.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_worker_plan.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_production_loop_plan.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_product_loop_check.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_production_loop_smoke.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_worker_start_check.kind'));
        $this->assertSame('maturity_report', data_get($commandsById, 'voice_realtime_rivals_report.kind'));
        $this->assertSame('evidence_report', data_get($commandsById, 'capture_inbox_pipeline_report.kind'));
        $this->assertSame('maintenance', data_get($commandsById, 'capture_inbox_pipeline_backfill_contracts.kind'));
        $this->assertSame('php artisan atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --write --json', data_get($commandsById, 'capture_inbox_pipeline_backfill_contracts.write_command'));
        $this->assertSame('evidence_report', data_get($commandsById, 'task_orchestration_report.kind'));
        $this->assertSame('maintenance', data_get($commandsById, 'task_orchestration_backfill_receipts.kind'));
        $this->assertSame('php artisan atlas:ai:task-orchestration-backfill-receipts --hours=720 --write --json', data_get($commandsById, 'task_orchestration_backfill_receipts.write_command'));
        $this->assertSame('evidence_report', data_get($commandsById, 'tool_action_runtime_report.kind'));
        $this->assertSame('evidence_report', data_get($commandsById, 'long_running_work_report.kind'));
        $this->assertSame('maintenance', data_get($commandsById, 'long_running_work_declare_baseline.kind'));
        $this->assertSame('php artisan atlas:ai:long-running-work-declare-baseline --apply --json', data_get($commandsById, 'long_running_work_declare_baseline.write_command'));
        $this->assertSame('evidence_report', data_get($commandsById, 'proactive_layer_report.kind'));
        $this->assertSame('validation', data_get($commandsById, 'runtime_language_boundary.kind'));
        $this->assertSame('/ai/runtime-boundary', data_get($commandsById, 'runtime_language_boundary.api_endpoint'));
        $this->assertSame('atlas_runtime_boundary', data_get($commandsById, 'runtime_language_boundary.mcp_tool'));
        $this->assertSame('ap201_runtime_language_boundary_contract', data_get($commandsById, 'runtime_language_boundary.scan_id'));
    }

    public function test_operation_ids_normalize_command_ids_consistently(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);

        $this->assertSame([
            'architecture_operations',
            'feature_placement',
        ], $catalog->operationIds([
            ['id' => 'architecture_operations'],
            ['id' => null],
            ['id' => 'feature_placement'],
            ['id' => 42],
        ]));
    }

    public function test_catalog_filters_architecture_operations_by_id_and_kind(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);

        $byId = $catalog->summary(['id' => 'provider_performance_report']);
        $byKind = $catalog->summary(['kind' => 'evidence_report']);
        $bySection = $catalog->summary(['section' => 'arquitetura_mae']);
        $byRuntimeSurface = $catalog->summary(['surface' => 'runtime']);
        $byRuntimeOwnerLayer = $catalog->summary(['owner_layer' => 'runtime']);
        $byUnknownSection = $catalog->summary(['section' => 'legacy']);

        $this->assertSame(['id' => 'provider_performance_report'], $byId['filters']);
        $this->assertSame(1, $byId['command_count']);
        $this->assertSame(['provider_performance_report'], $byId['operation_ids']);
        $this->assertSame('php artisan atlas:ai:provider-performance --hours=24 --json', data_get($byId, 'commands.0.command'));

        $this->assertSame(['section' => 'arquitetura_mae'], $bySection['filters']);
        $this->assertSame(104, $bySection['command_count']);
        $this->assertContains('architecture_operations', $bySection['operation_ids']);
        $this->assertSame(['section' => 'legacy'], $byUnknownSection['filters']);
        $this->assertSame(0, $byUnknownSection['command_count']);
        $this->assertSame([], $byUnknownSection['operation_ids']);

        $this->assertSame(['surface' => 'runtime'], $byRuntimeSurface['filters']);
        $this->assertSame(1, $byRuntimeSurface['command_count']);
        $this->assertSame(['voice_python_runtime_contract_test'], $byRuntimeSurface['operation_ids']);
        $this->assertSame(
            'PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests',
            data_get($byRuntimeSurface, 'commands.0.command'),
        );

        $this->assertSame(['owner_layer' => 'runtime'], $byRuntimeOwnerLayer['filters']);
        $this->assertSame(1, $byRuntimeOwnerLayer['command_count']);
        $this->assertSame(['runtime_language_boundary'], $byRuntimeOwnerLayer['operation_ids']);
        $this->assertSame('php artisan atlas:ai:runtime-boundary --json', data_get($byRuntimeOwnerLayer, 'commands.0.command'));
        $this->assertTrue(data_get($byRuntimeOwnerLayer, 'commands.0.pre_implementation_gate'));
        $this->assertSame(['python_ai_data', 'go_edge', 'swift_native_mac'], data_get($byRuntimeOwnerLayer, 'commands.0.governed_runtimes'));
        $this->assertContains('livekit', data_get($byRuntimeOwnerLayer, 'commands.0.required_for_terms'));

        $byValidation = $catalog->summary(['kind' => 'validation']);
        $this->assertSame(9, $byValidation['command_count']);
        $this->assertSame([
            'architecture_validate',
            'documentation_health',
            'voice_realtime_scripted_smoke',
            'voice_realtime_callback_smoke',
            'voice_realtime_callback_sequence_smoke',
            'voice_realtime_preflight',
            'voice_realtime_production_loop_smoke',
            'voice_realtime_runtime_certification',
            'runtime_language_boundary',
        ], $byValidation['operation_ids']);

        $byMaintenance = $catalog->summary(['kind' => 'maintenance']);
        $this->assertSame(6, $byMaintenance['command_count']);
        $this->assertSame(['knowledge_sync', 'code_intelligence_index', 'ledger_projection_worker', 'capture_inbox_pipeline_backfill_contracts', 'task_orchestration_backfill_receipts', 'long_running_work_declare_baseline'], $byMaintenance['operation_ids']);

        $byBootstrap = $catalog->summary(['kind' => 'bootstrap']);
        $this->assertSame(1, $byBootstrap['command_count']);
        $this->assertSame(['session_bootstrap'], $byBootstrap['operation_ids']);

        $byGovernanceGate = $catalog->summary(['kind' => 'governance_gate']);
        $this->assertSame(27, $byGovernanceGate['command_count']);
        $this->assertSame([
            'feature_placement',
            'documentation_split_plan',
            'documentation_reality_score',
            'documentation_reality_acceptance_matrix',
            'documentation_enforcement',
            'code_reality_anti_duplicate',
            'code_reality_reality_audit',
            'code_reality_global_duplication_audit',
            'code_reality_status_drift_audit',
            'code_reality_reachability',
            'code_reality_deletion_preflight',
            'universal_reality_cartography_navigation_slice',
            'universal_reality_cartography_visual_scene',
            'universal_reality_cartography_human_clarity',
            'software_twin_quality_score',
            'software_twin_impact',
            'software_twin_snapshot',
            'verified_evolution_boundary_contract',
            'verified_evolution_proof_plan',
            'verified_evolution_execution_contract',
            'verified_evolution_scope_drift_watch',
            'verified_evolution_patch_simulation',
            'verified_evolution_outcome_bridge',
            'ap_agent_workflow_registry',
            'local_rag_benchmark_rivals_shadow_plan',
            'local_rag_benchmark_rivals_shadow_case_contract',
            'local_rag_graph_promotion_review',
        ], $byGovernanceGate['operation_ids']);

        $byProviderProjection = $catalog->summary(['kind' => 'provider_projection']);
        $this->assertSame(2, $byProviderProjection['command_count']);
        $this->assertSame(['provider_projection_status', 'provider_projection_write'], $byProviderProjection['operation_ids']);

        $byProviderEvolution = $catalog->summary(['kind' => 'provider_evolution']);
        $this->assertSame(2, $byProviderEvolution['command_count']);
        $this->assertSame(['provider_release_review', 'provider_release_sources'], $byProviderEvolution['operation_ids']);
        $this->assertSame('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', data_get($byProviderEvolution, 'commands.0.command'));
        $this->assertSame('php artisan atlas:ai:provider-release-sources --json', data_get($byProviderEvolution, 'commands.1.command'));
        $this->assertSame('/ai/provider-release-sources', data_get($byProviderEvolution, 'commands.1.api_endpoint'));

        $byRuntimeReadiness = $catalog->summary(['kind' => 'runtime_readiness']);
        $this->assertSame(5, $byRuntimeReadiness['command_count']);
        $this->assertSame(['local_rag_readiness', 'voice_realtime_token_issuer_plan', 'voice_realtime_token_issuer_smoke', 'voice_realtime_livekit_server_probe', 'voice_realtime_pre_start_health_checks_smoke'], $byRuntimeReadiness['operation_ids']);
        $this->assertSame('php artisan atlas:ai:local-rag-readiness --json', data_get($byRuntimeReadiness, 'commands.0.command'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md', data_get($byRuntimeReadiness, 'commands.0.doc'));
        $this->assertSame('php artisan atlas:ai:voice token-issuer-plan --json', data_get($byRuntimeReadiness, 'commands.1.command'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byRuntimeReadiness, 'commands.1.doc'));
        $this->assertSame('php artisan atlas:ai:voice token-issuer-smoke --json', data_get($byRuntimeReadiness, 'commands.2.command'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byRuntimeReadiness, 'commands.2.doc'));
        $this->assertSame('php artisan atlas:ai:voice livekit-server-probe --json', data_get($byRuntimeReadiness, 'commands.3.command'));
        $this->assertSame('/ai/voice/runtime/livekit-server-probe', data_get($byRuntimeReadiness, 'commands.3.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/livekit-server-probe', data_get($byRuntimeReadiness, 'commands.3.mobile_endpoint'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', data_get($byRuntimeReadiness, 'commands.3.doc'));
        $this->assertSame('php artisan atlas:ai:voice pre-start-health-checks-smoke --json', data_get($byRuntimeReadiness, 'commands.4.command'));
        $this->assertSame('/ai/voice/runtime/pre-start-health-checks-smoke', data_get($byRuntimeReadiness, 'commands.4.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke', data_get($byRuntimeReadiness, 'commands.4.mobile_endpoint'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byRuntimeReadiness, 'commands.4.doc'));

        $byRuntimeBenchmark = $catalog->summary(['kind' => 'runtime_benchmark']);
        $this->assertSame(1, $byRuntimeBenchmark['command_count']);
        $this->assertSame(['local_rag_benchmark'], $byRuntimeBenchmark['operation_ids']);
        $this->assertSame('php artisan atlas:ai:local-rag-benchmark --json', data_get($byRuntimeBenchmark, 'commands.0.command'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md', data_get($byRuntimeBenchmark, 'commands.0.doc'));

        $byLocalRagGraphPromotionReview = $catalog->summary(['id' => 'local_rag_graph_promotion_review']);
        $this->assertSame(1, $byLocalRagGraphPromotionReview['command_count']);
        $this->assertSame('governance_gate', data_get($byLocalRagGraphPromotionReview, 'commands.0.kind'));
        $this->assertSame('php artisan atlas:ai:local-rag-benchmark --json', data_get($byLocalRagGraphPromotionReview, 'commands.0.command'));
        $this->assertSame('docs/ap/AP-683-local-rag-graph-promotion-review.md', data_get($byLocalRagGraphPromotionReview, 'commands.0.doc'));
        $this->assertSame('atlas.local_rag_graph_promotion_review.v1', data_get($byLocalRagGraphPromotionReview, 'commands.0.review_contract'));

        $byExternalVectorRagPreflightInbox = $catalog->summary(['id' => 'external_vector_rag_preflight_inbox']);
        $this->assertSame(1, $byExternalVectorRagPreflightInbox['command_count']);
        $this->assertSame('review_queue', data_get($byExternalVectorRagPreflightInbox, 'commands.0.kind'));
        $this->assertSame('php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json', data_get($byExternalVectorRagPreflightInbox, 'commands.0.command'));
        $this->assertSame('docs/engineering-knowledge-base/memory/retrieval-and-context.md', data_get($byExternalVectorRagPreflightInbox, 'commands.0.doc'));
        $this->assertSame('atlas.external_vector_rag.preflight_inbox.v1', data_get($byExternalVectorRagPreflightInbox, 'commands.0.review_contract'));

        $byCodeIntelligenceReport = $catalog->summary(['kind' => 'code_intelligence_report']);
        $this->assertSame(1, $byCodeIntelligenceReport['command_count']);
        $this->assertSame(['external_graph_harness_report'], $byCodeIntelligenceReport['operation_ids']);
        $this->assertSame('php artisan atlas:ai:external-graph-harness --json', data_get($byCodeIntelligenceReport, 'commands.0.command'));
        $this->assertSame('/ai/external-graph-harness', data_get($byCodeIntelligenceReport, 'commands.0.api_endpoint'));

        $bySurfaceContract = $catalog->summary(['kind' => 'surface_contract']);
        $this->assertSame(2, $bySurfaceContract['command_count']);
        $this->assertSame(['voice_realtime_contract', 'voice_realtime_bootstrap'], $bySurfaceContract['operation_ids']);

        $byRuntimeContract = $catalog->summary(['kind' => 'runtime_contract']);
        $this->assertSame(11, $byRuntimeContract['command_count']);
        $this->assertSame(['voice_realtime_dependencies', 'voice_realtime_dependency_install_plan', 'voice_realtime_scripted_worker_example', 'voice_realtime_callback_loop_check', 'voice_realtime_activation_contract', 'voice_realtime_sdk_check', 'voice_realtime_worker_plan', 'voice_realtime_production_loop_plan', 'voice_realtime_product_loop_check', 'voice_realtime_worker_start_check', 'voice_python_runtime_contract_test'], $byRuntimeContract['operation_ids']);

        $this->assertSame(['kind' => 'evidence_report'], $byKind['filters']);
        $this->assertSame(17, $byKind['command_count']);
        $this->assertNotContains('architecture_operations', $byKind['operation_ids']);
        $this->assertContains('voice_realtime_readiness', $byKind['operation_ids']);
        $this->assertContains('kernel_slo_report', $byKind['operation_ids']);
        $this->assertContains('agent_behavior_report', $byKind['operation_ids']);
        $this->assertContains('dynamic_compute_market_report', $byKind['operation_ids']);
        $this->assertContains('provider_cost_rates_missing', $byKind['operation_ids']);
        $this->assertContains('decision_receipt_report', $byKind['operation_ids']);
        $this->assertContains('ledger_replay', $byKind['operation_ids']);
        $this->assertContains('inbox_action_report', $byKind['operation_ids']);
        $this->assertContains('capture_inbox_pipeline_report', $byKind['operation_ids']);
        $this->assertContains('task_orchestration_report', $byKind['operation_ids']);
        $this->assertContains('tool_action_runtime_report', $byKind['operation_ids']);
        $this->assertContains('long_running_work_report', $byKind['operation_ids']);
        $this->assertContains('proactive_layer_report', $byKind['operation_ids']);

        $byMaintenance = $catalog->summary(['kind' => 'maintenance']);
        $this->assertContains('long_running_work_declare_baseline', $byMaintenance['operation_ids']);

        $byReadiness = $catalog->summary(['kind' => 'readiness']);
        $this->assertSame(1, $byReadiness['command_count']);
        $this->assertSame(['architecture_readiness'], $byReadiness['operation_ids']);
        $this->assertSame('php artisan atlas:ai:architecture-readiness --json', data_get($byReadiness, 'commands.0.command'));
        $this->assertSame('/ai/architecture/readiness', data_get($byReadiness, 'commands.0.api_endpoint'));

        $byPlanning = $catalog->summary(['kind' => 'planning_surface']);
        $this->assertSame(1, $byPlanning['command_count']);
        $this->assertSame(['strategic_decision_review'], $byPlanning['operation_ids']);
        $this->assertSame('php artisan atlas:ai:strategic-decision review --json', data_get($byPlanning, 'commands.0.command'));

        $byMaturity = $catalog->summary(['kind' => 'maturity_report']);
        $this->assertSame(4, $byMaturity['command_count']);
        $this->assertSame(['local_rag_benchmark_rivals_report', 'voice_realtime_rivals_report', 'qualitative_levels_report', 'rivals_strategy_report'], $byMaturity['operation_ids']);
        $this->assertSame('php artisan atlas:ai:local-rag-benchmark --rivals-report --json', data_get($byMaturity, 'commands.0.command'));
        $this->assertSame('php artisan atlas:ai:voice rivals --hours=24 --json', data_get($byMaturity, 'commands.1.command'));
        $this->assertSame('php artisan atlas:ai:qualitative-levels --hours=720 --json', data_get($byMaturity, 'commands.2.command'));
        $this->assertSame('php artisan atlas:ai:rivals-strategy report --hours=8760 --json', data_get($byMaturity, 'commands.3.command'));

        $byReviewQueue = $catalog->summary(['kind' => 'review_queue']);
        $this->assertSame(5, $byReviewQueue['command_count']);
        $this->assertSame(['local_rag_benchmark_rivals_shadow_inbox', 'local_rag_benchmark_rivals_inbox', 'external_vector_rag_preflight_inbox', 'voice_realtime_promotion_review_packet', 'rivals_strategy_due_reviews'], $byReviewQueue['operation_ids']);

        $byReviewAction = $catalog->summary(['kind' => 'review_action']);
        $this->assertSame(2, $byReviewAction['command_count']);
        $this->assertSame(['provider_cost_rates_upsert', 'rivals_strategy_record_review'], $byReviewAction['operation_ids']);

        $byCuratorReview = $catalog->summary(['kind' => 'curator_review']);
        $this->assertSame(4, $byCuratorReview['command_count']);
        $this->assertSame(['provider_performance_curator_review', 'provider_release_curator_review', 'agent_behavior_curator_review', 'voice_realtime_curator_review'], $byCuratorReview['operation_ids']);
        $this->assertSame('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', data_get($byCuratorReview, 'commands.0.command'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', data_get($byCuratorReview, 'commands.1.command'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', data_get($byCuratorReview, 'commands.2.command'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json', data_get($byCuratorReview, 'commands.3.command'));

        $byCostRateAction = $catalog->summary(['id' => 'provider_cost_rates_upsert']);
        $this->assertSame(1, $byCostRateAction['command_count']);
        $this->assertSame('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', data_get($byCostRateAction, 'commands.0.command'));

        $byDynamicComputeMarket = $catalog->summary(['id' => 'dynamic_compute_market_report']);
        $this->assertSame(1, $byDynamicComputeMarket['command_count']);
        $this->assertSame('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', data_get($byDynamicComputeMarket, 'commands.0.command'));

        $byVoiceReadiness = $catalog->summary(['id' => 'voice_realtime_readiness']);
        $this->assertSame(1, $byVoiceReadiness['command_count']);
        $this->assertSame('php artisan atlas:ai:voice readiness --hours=24 --json', data_get($byVoiceReadiness, 'commands.0.command'));
        $this->assertSame('evidence_report', data_get($byVoiceReadiness, 'commands.0.kind'));
        $this->assertSame('/ai/voice/readiness', data_get($byVoiceReadiness, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/readiness', data_get($byVoiceReadiness, 'commands.0.mobile_endpoint'));

        $byVoiceRivals = $catalog->summary(['id' => 'voice_realtime_rivals_report']);
        $this->assertSame(1, $byVoiceRivals['command_count']);
        $this->assertSame('php artisan atlas:ai:voice rivals --hours=24 --json', data_get($byVoiceRivals, 'commands.0.command'));
        $this->assertSame('maturity_report', data_get($byVoiceRivals, 'commands.0.kind'));
        $this->assertSame('/ai/voice/rivals', data_get($byVoiceRivals, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/rivals', data_get($byVoiceRivals, 'commands.0.mobile_endpoint'));

        $byVoiceScriptedSmoke = $catalog->summary(['id' => 'voice_realtime_scripted_smoke']);
        $this->assertSame(1, $byVoiceScriptedSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice scripted-smoke --json', data_get($byVoiceScriptedSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceScriptedSmoke, 'commands.0.kind'));

        $byVoiceCallbackSmoke = $catalog->summary(['id' => 'voice_realtime_callback_smoke']);
        $this->assertSame(1, $byVoiceCallbackSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice callback-smoke --json', data_get($byVoiceCallbackSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceCallbackSmoke, 'commands.0.kind'));

        $byVoiceCallbackSequenceSmoke = $catalog->summary(['id' => 'voice_realtime_callback_sequence_smoke']);
        $this->assertSame(1, $byVoiceCallbackSequenceSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice callback-sequence-smoke --json', data_get($byVoiceCallbackSequenceSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceCallbackSequenceSmoke, 'commands.0.kind'));

        $byVoiceCallbackLoopCheck = $catalog->summary(['id' => 'voice_realtime_callback_loop_check']);
        $this->assertSame(1, $byVoiceCallbackLoopCheck['command_count']);
        $this->assertSame('php artisan atlas:ai:voice callback-loop-check --json', data_get($byVoiceCallbackLoopCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceCallbackLoopCheck, 'commands.0.kind'));

        $byVoicePreflight = $catalog->summary(['id' => 'voice_realtime_preflight']);
        $this->assertSame(1, $byVoicePreflight['command_count']);
        $this->assertSame('php artisan atlas:ai:voice preflight --json', data_get($byVoicePreflight, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoicePreflight, 'commands.0.kind'));

        $byVoiceActivationContract = $catalog->summary(['id' => 'voice_realtime_activation_contract']);
        $this->assertSame(1, $byVoiceActivationContract['command_count']);
        $this->assertSame('php artisan atlas:ai:voice activation-contract --json', data_get($byVoiceActivationContract, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceActivationContract, 'commands.0.kind'));

        $byVoiceSdkCheck = $catalog->summary(['id' => 'voice_realtime_sdk_check']);
        $this->assertSame(1, $byVoiceSdkCheck['command_count']);
        $this->assertSame('php artisan atlas:ai:voice sdk-check --json', data_get($byVoiceSdkCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceSdkCheck, 'commands.0.kind'));

        $byVoiceWorkerPlan = $catalog->summary(['id' => 'voice_realtime_worker_plan']);
        $this->assertSame(1, $byVoiceWorkerPlan['command_count']);
        $this->assertSame('php artisan atlas:ai:voice worker-plan --json', data_get($byVoiceWorkerPlan, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceWorkerPlan, 'commands.0.kind'));

        $byVoiceProductionLoopPlan = $catalog->summary(['id' => 'voice_realtime_production_loop_plan']);
        $this->assertSame(1, $byVoiceProductionLoopPlan['command_count']);
        $this->assertSame('php artisan atlas:ai:voice production-loop-plan --json', data_get($byVoiceProductionLoopPlan, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceProductionLoopPlan, 'commands.0.kind'));

        $byVoiceProductLoopCheck = $catalog->summary(['id' => 'voice_realtime_product_loop_check']);
        $this->assertSame(1, $byVoiceProductLoopCheck['command_count']);
        $this->assertSame('php artisan atlas:ai:voice product-loop-check --json', data_get($byVoiceProductLoopCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceProductLoopCheck, 'commands.0.kind'));

        $byVoicePreStartHealthChecksSmoke = $catalog->summary(['id' => 'voice_realtime_pre_start_health_checks_smoke']);
        $this->assertSame(1, $byVoicePreStartHealthChecksSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice pre-start-health-checks-smoke --json', data_get($byVoicePreStartHealthChecksSmoke, 'commands.0.command'));
        $this->assertSame('runtime_readiness', data_get($byVoicePreStartHealthChecksSmoke, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/pre-start-health-checks-smoke', data_get($byVoicePreStartHealthChecksSmoke, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke', data_get($byVoicePreStartHealthChecksSmoke, 'commands.0.mobile_endpoint'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byVoicePreStartHealthChecksSmoke, 'commands.0.doc'));

        $byVoiceProductionLoopSmoke = $catalog->summary(['id' => 'voice_realtime_production_loop_smoke']);
        $this->assertSame(1, $byVoiceProductionLoopSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice production-loop-smoke --json', data_get($byVoiceProductionLoopSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceProductionLoopSmoke, 'commands.0.kind'));

        $byVoiceWorkerStartCheck = $catalog->summary(['id' => 'voice_realtime_worker_start_check']);
        $this->assertSame(1, $byVoiceWorkerStartCheck['command_count']);
        $this->assertSame('php artisan atlas:ai:voice worker-start-check --json', data_get($byVoiceWorkerStartCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceWorkerStartCheck, 'commands.0.kind'));

        $byVoiceRuntimeCertification = $catalog->summary(['id' => 'voice_realtime_runtime_certification']);
        $this->assertSame(1, $byVoiceRuntimeCertification['command_count']);
        $this->assertSame('php artisan atlas:ai:voice runtime-certify --json', data_get($byVoiceRuntimeCertification, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceRuntimeCertification, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/certification', data_get($byVoiceRuntimeCertification, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/certification', data_get($byVoiceRuntimeCertification, 'commands.0.mobile_endpoint'));

        $byVoicePromotionReviewPacket = $catalog->summary(['id' => 'voice_realtime_promotion_review_packet']);
        $this->assertSame(1, $byVoicePromotionReviewPacket['command_count']);
        $this->assertSame('php artisan atlas:ai:voice promotion-review-packet --hours=24 --json', data_get($byVoicePromotionReviewPacket, 'commands.0.command'));
        $this->assertSame('review_queue', data_get($byVoicePromotionReviewPacket, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/promotion-review-packet', data_get($byVoicePromotionReviewPacket, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/promotion-review-packet', data_get($byVoicePromotionReviewPacket, 'commands.0.mobile_endpoint'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byVoicePromotionReviewPacket, 'commands.0.doc'));

        $byVoiceTokenIssuerPlan = $catalog->summary(['id' => 'voice_realtime_token_issuer_plan']);
        $this->assertSame(1, $byVoiceTokenIssuerPlan['command_count']);
        $this->assertSame('php artisan atlas:ai:voice token-issuer-plan --json', data_get($byVoiceTokenIssuerPlan, 'commands.0.command'));
        $this->assertSame('runtime_readiness', data_get($byVoiceTokenIssuerPlan, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/token-issuer-plan', data_get($byVoiceTokenIssuerPlan, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/token-issuer-plan', data_get($byVoiceTokenIssuerPlan, 'commands.0.mobile_endpoint'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byVoiceTokenIssuerPlan, 'commands.0.doc'));

        $byVoiceTokenIssuerSmoke = $catalog->summary(['id' => 'voice_realtime_token_issuer_smoke']);
        $this->assertSame(1, $byVoiceTokenIssuerSmoke['command_count']);
        $this->assertSame('php artisan atlas:ai:voice token-issuer-smoke --json', data_get($byVoiceTokenIssuerSmoke, 'commands.0.command'));
        $this->assertSame('runtime_readiness', data_get($byVoiceTokenIssuerSmoke, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/token-issuer-smoke', data_get($byVoiceTokenIssuerSmoke, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/token-issuer-smoke', data_get($byVoiceTokenIssuerSmoke, 'commands.0.mobile_endpoint'));
        $this->assertSame('docs/ap/AP-687-voice-realtime-production-promotion-gate.md', data_get($byVoiceTokenIssuerSmoke, 'commands.0.doc'));

        $byVoiceDependencies = $catalog->summary(['id' => 'voice_realtime_dependencies']);
        $this->assertSame(1, $byVoiceDependencies['command_count']);
        $this->assertSame('php artisan atlas:ai:voice dependencies --json', data_get($byVoiceDependencies, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceDependencies, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/dependencies', data_get($byVoiceDependencies, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependencies', data_get($byVoiceDependencies, 'commands.0.mobile_endpoint'));

        $byDependencyInstallPlan = $catalog->summary(['id' => 'voice_realtime_dependency_install_plan']);
        $this->assertSame(1, $byDependencyInstallPlan['command_count']);
        $this->assertSame('php artisan atlas:ai:voice dependency-install-plan --json', data_get($byDependencyInstallPlan, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byDependencyInstallPlan, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/dependency-install-plan', data_get($byDependencyInstallPlan, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependency-install-plan', data_get($byDependencyInstallPlan, 'commands.0.mobile_endpoint'));
        $this->assertFalse(data_get($byDependencyInstallPlan, 'commands.0.operator_managed_policy.daemon_start_allowed'));
    }

    public function test_catalog_does_not_publish_chat_macro_commands_for_operational_artisan_flows(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);

        foreach ($catalog->commands() as $operation) {
            $command = (string) $operation['command'];

            $this->assertStringStartsNotWith(
                'atlas ai ',
                $command,
                sprintf(
                    'Operation [%s] must publish the executable Artisan command, not the atlas chat macro command.',
                    $operation['id']
                )
            );

            $this->assertStringStartsNotWith(
                'atlas ledger ',
                $command,
                sprintf(
                    'Operation [%s] must publish the executable Artisan ledger command, not an unresolved atlas ledger alias.',
                    $operation['id']
                )
            );
        }
    }
}
