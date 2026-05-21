<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiArchitectureOperationsApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_architecture_operations_catalog(): void
    {
        $response = $this->getJson('/ai/architecture/operations', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('architecture_operations.section', 'arquitetura_mae')
            ->assertJsonPath('architecture_operations.command_count', 88)
            ->assertJsonPath('architecture_operations.commands.0.id', 'architecture_operations')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'catalog')
            ->assertJsonPath('architecture_operations.commands.0.surface', 'cli');

        $commands = array_column($response->json('architecture_operations.commands'), 'command');

        $this->assertContains('php artisan atlas:ai:architecture-operations --json', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-validate', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-readiness --json', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('php artisan atlas:ai:session-bootstrap --task="<task>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:place-feature "<feature>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:docs-split-plan --json', $commands);
        $this->assertContains('php artisan atlas:documentation-reality score --strict --json', $commands);
        $this->assertContains('php artisan atlas:documentation-reality acceptance --strict --json', $commands);
        $this->assertContains('php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json', $commands);
        $this->assertContains('php artisan atlas:code-reality reachability --target="<target>" --json', $commands);
        $this->assertContains('php artisan atlas:universal-reality-cartography navigation-slice --strict --json', $commands);
        $this->assertContains('php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json', $commands);
        $this->assertContains('php artisan atlas:ai:ap-agent-workflow --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-sources --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --schedule-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --emit-rivals-shadow-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-report --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json', $commands);
        $this->assertContains('atlas engineering knowledge sync --prune --json', $commands);
        $this->assertContains('atlas engineering knowledge index-code --prune --summary-only --json', $commands);
        $this->assertContains('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $commands);
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
        $this->assertContains('php artisan atlas:ai:voice worker-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice production-loop-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice product-loop-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice production-loop-smoke --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice worker-start-check --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice runtime-certify --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice readiness --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:voice rivals --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('php artisan atlas:ai:inbox-action-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:capture-inbox-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --json', $commands);
        $this->assertContains('php artisan atlas:ai:task-orchestration-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:task-orchestration-backfill-receipts --hours=720 --json', $commands);
        $this->assertContains('php artisan atlas:ai:tool-action-runtime-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:long-running-work-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:proactive-layer-report --hours=24 --json', $commands);
        $this->assertContains('php artisan atlas:ai:decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('php artisan atlas:ai:ledger <id> --json', $commands);
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $commands);
        $this->assertContains('architecture_operations', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('local_rag_graph_promotion_review', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('external_vector_rag_preflight_inbox', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('capture_inbox_pipeline_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('task_orchestration_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('tool_action_runtime_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('long_running_work_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('proactive_layer_report', $response->json('architecture_operations.operation_ids'));

        $commandsById = collect($response->json('architecture_operations.commands'))->keyBy('id');

        $this->assertSame('atlas.voice_realtime.python_runtime_plan.v1', data_get($commandsById, 'voice_realtime_dependencies.runtime_dependency_contract'));
        $this->assertSame('ATLAS_VOICE_PYTHON_BIN', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.environment_variable'));
        $this->assertSame('atlas_ai.voice_realtime.python_binary', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.config_key'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.pre_implementation_gate'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.auto_install'));
        $this->assertContains('configured_binary', data_get($commandsById, 'voice_realtime_dependencies.output_contract.python_runtime'));
        $this->assertSame('atlas.voice_realtime.dependency_install_plan.v1', data_get($commandsById, 'voice_realtime_dependency_install_plan.runtime_dependency_contract'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependency_install_plan.operator_managed_policy.daemon_start_allowed'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/architecture/operations')
            ->assertUnauthorized();
    }

    public function test_api_filters_architecture_operations_catalog(): void
    {
        $response = $this->getJson('/ai/architecture/operations?kind=evidence_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.kind', 'evidence_report')
            ->assertJsonPath('architecture_operations.command_count', 17);

        $this->assertContains('voice_realtime_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('provider_performance_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('agent_behavior_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('dynamic_compute_market_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('provider_cost_rates_missing', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('capture_inbox_pipeline_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('task_orchestration_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('tool_action_runtime_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('long_running_work_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('proactive_layer_report', $response->json('architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', $response->json('architecture_operations.operation_ids'));

        $this->getJson('/ai/architecture/operations?section=arquitetura_mae', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.section', 'arquitetura_mae')
            ->assertJsonPath('architecture_operations.command_count', 88)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'architecture_operations');

        $this->getJson('/ai/architecture/operations?surface=runtime', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.surface', 'runtime')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'voice_python_runtime_contract_test')
            ->assertJsonPath('architecture_operations.commands.0.command', 'PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests');

        $this->getJson('/ai/architecture/operations?owner_layer=runtime', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.owner_layer', 'runtime')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'runtime_language_boundary')
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:runtime-boundary --json')
            ->assertJsonPath('architecture_operations.commands.0.owner_layer', 'runtime')
            ->assertJsonPath('architecture_operations.commands.0.pre_implementation_gate', true)
            ->assertJsonPath('architecture_operations.commands.0.governed_runtimes.0', 'python_ai_data')
            ->assertJsonPath('architecture_operations.commands.0.governed_runtimes.1', 'go_edge')
            ->assertJsonPath('architecture_operations.commands.0.governed_runtimes.2', 'swift_native_mac');

        $this->getJson('/ai/architecture/operations?id=provider_performance_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_performance_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:provider-performance --hours=24 --json');

        $this->getJson('/ai/architecture/operations?id=provider_cost_rates_upsert', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_cost_rates_upsert')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json');

        $this->getJson('/ai/architecture/operations?id=dynamic_compute_market_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'dynamic_compute_market_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json');

        $this->getJson('/ai/architecture/operations?id=external_graph_harness_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'external_graph_harness_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:external-graph-harness --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'code_intelligence_report')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/external-graph-harness');

        $this->getJson('/ai/architecture/operations?id=architecture_readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'architecture_readiness')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:architecture-readiness --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'readiness')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/architecture/readiness');

        $this->getJson('/ai/architecture/operations?kind=provider_evolution', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.kind', 'provider_evolution')
            ->assertJsonPath('architecture_operations.command_count', 2)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'provider_release_review')
            ->assertJsonPath('architecture_operations.operation_ids.1', 'provider_release_sources')
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/provider-release-review')
            ->assertJsonPath('architecture_operations.commands.1.command', 'php artisan atlas:ai:provider-release-sources --json')
            ->assertJsonPath('architecture_operations.commands.1.api_endpoint', '/ai/provider-release-sources');

        $this->getJson('/ai/architecture/operations?kind=bootstrap', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.kind', 'bootstrap')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'session_bootstrap')
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:session-bootstrap --task="<task>" --json')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/session-bootstrap');

        $this->getJson('/ai/architecture/operations?kind=governance_gate', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.kind', 'governance_gate')
            ->assertJsonPath('architecture_operations.command_count', 12)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'feature_placement')
            ->assertJsonPath('architecture_operations.operation_ids.1', 'documentation_split_plan')
            ->assertJsonPath('architecture_operations.operation_ids.2', 'documentation_reality_score')
            ->assertJsonPath('architecture_operations.operation_ids.3', 'documentation_reality_acceptance_matrix')
            ->assertJsonPath('architecture_operations.operation_ids.4', 'code_reality_anti_duplicate')
            ->assertJsonPath('architecture_operations.operation_ids.5', 'code_reality_reachability')
            ->assertJsonPath('architecture_operations.operation_ids.6', 'universal_reality_cartography_navigation_slice')
            ->assertJsonPath('architecture_operations.operation_ids.7', 'universal_reality_cartography_visual_scene')
            ->assertJsonPath('architecture_operations.operation_ids.8', 'ap_agent_workflow_registry')
            ->assertJsonPath('architecture_operations.operation_ids.9', 'local_rag_benchmark_rivals_shadow_plan')
            ->assertJsonPath('architecture_operations.operation_ids.10', 'local_rag_benchmark_rivals_shadow_case_contract')
            ->assertJsonPath('architecture_operations.operation_ids.11', 'local_rag_graph_promotion_review')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/feature-placement')
            ->assertJsonPath('architecture_operations.commands.1.api_endpoint', '/ai/docs-split-plan')
            ->assertJsonPath('architecture_operations.commands.2.command', 'php artisan atlas:documentation-reality score --strict --json')
            ->assertJsonPath('architecture_operations.commands.3.command', 'php artisan atlas:documentation-reality acceptance --strict --json')
            ->assertJsonPath('architecture_operations.commands.4.command', 'php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json')
            ->assertJsonPath('architecture_operations.commands.5.command', 'php artisan atlas:code-reality reachability --target="<target>" --json')
            ->assertJsonPath('architecture_operations.commands.6.command', 'php artisan atlas:universal-reality-cartography navigation-slice --strict --json')
            ->assertJsonPath('architecture_operations.commands.7.command', 'php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json')
            ->assertJsonPath('architecture_operations.commands.8.command', 'php artisan atlas:ai:ap-agent-workflow --json')
            ->assertJsonPath('architecture_operations.commands.9.doc', 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md')
            ->assertJsonPath('architecture_operations.commands.9.review_contract', 'atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1')
            ->assertJsonPath('architecture_operations.commands.10.review_contract', 'atlas.memory_retrieval_rivals_shadow_case_contract.v1')
            ->assertJsonPath('architecture_operations.commands.11.review_contract', 'atlas.local_rag_graph_promotion_review.v1');

        $this->getJson('/ai/architecture/operations?id=external_vector_rag_preflight_inbox', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'external_vector_rag_preflight_inbox')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.kind', 'review_queue')
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json')
            ->assertJsonPath('architecture_operations.commands.0.review_contract', 'atlas.external_vector_rag.preflight_inbox.v1');

        $this->getJson('/ai/architecture/operations?id=provider_performance_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_performance_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=provider_release_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_release_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=agent_behavior_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'agent_behavior_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_readiness')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice readiness --hours=24 --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'evidence_report')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/readiness')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/readiness');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_rivals_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_rivals_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice rivals --hours=24 --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'maturity_report')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/rivals')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/rivals');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_scripted_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_scripted_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice scripted-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice callback-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_sequence_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_sequence_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice callback-sequence-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_loop_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_loop_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice callback-loop-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_preflight', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_preflight')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice preflight --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_activation_contract', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_activation_contract')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice activation-contract --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_sdk_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_sdk_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice sdk-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_worker_plan', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_worker_plan')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice worker-plan --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_production_loop_plan', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_production_loop_plan')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice production-loop-plan --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_product_loop_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_product_loop_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice product-loop-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_production_loop_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_production_loop_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice production-loop-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_worker_start_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_worker_start_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice worker-start-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_runtime_certification', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_runtime_certification')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice runtime-certify --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/runtime/certification')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/runtime/certification');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_dependencies', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_dependencies')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:voice dependencies --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/runtime/dependencies')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/runtime/dependencies');

        $this->getJson('/ai/architecture/operations?id=runtime_language_boundary', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'runtime_language_boundary')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:runtime-boundary --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/runtime-boundary')
            ->assertJsonPath('architecture_operations.commands.0.mcp_tool', 'atlas_runtime_boundary')
            ->assertJsonPath('architecture_operations.commands.0.scan_id', 'ap201_runtime_language_boundary_contract');
    }
}
