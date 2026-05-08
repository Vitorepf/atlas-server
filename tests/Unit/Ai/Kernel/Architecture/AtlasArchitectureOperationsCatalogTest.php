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
        $this->assertSame(54, $summary['command_count']);
        $this->assertSame($catalog->commands(), $summary['commands']);
        $this->assertSame([
            'architecture_operations',
            'architecture_validate',
            'architecture_readiness',
            'documentation_health',
            'session_bootstrap',
            'feature_placement',
            'documentation_split_plan',
            'ap_agent_workflow_registry',
            'provider_projection_status',
            'provider_projection_write',
            'provider_release_review',
            'knowledge_sync',
            'code_intelligence_index',
            'kernel_slo_report',
            'voice_realtime_contract',
            'voice_realtime_bootstrap',
            'voice_realtime_dependencies',
            'voice_realtime_scripted_worker_example',
            'voice_realtime_scripted_smoke',
            'voice_realtime_callback_smoke',
            'voice_realtime_callback_sequence_smoke',
            'voice_realtime_callback_loop_check',
            'voice_realtime_preflight',
            'voice_realtime_activation_contract',
            'voice_realtime_sdk_check',
            'voice_realtime_worker_plan',
            'voice_realtime_production_loop_plan',
            'voice_realtime_production_loop_smoke',
            'voice_realtime_worker_start_check',
            'voice_realtime_runtime_certification',
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
        ], $summary['operation_ids']);

        $commands = array_column($summary['commands'], 'command');

        $this->assertContains('atlas ai architecture-operations --json', $commands);
        $this->assertContains('atlas ai architecture-validate', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-readiness --json', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('php artisan atlas:ai:session-bootstrap --task="<task>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:place-feature "<feature>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:docs-split-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:ap-agent-workflow --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $commands);
        $this->assertContains('atlas engineering knowledge sync --prune --json', $commands);
        $this->assertContains('atlas engineering knowledge index-code --prune --json', $commands);
        $this->assertContains('atlas ai slo --hours=24 --json', $commands);
        $this->assertContains('atlas ai voice contract --json', $commands);
        $this->assertContains('atlas ai voice bootstrap --json', $commands);
        $this->assertContains('atlas ai voice dependencies --json', $commands);
        $this->assertContains('atlas ai voice scripted-example --json', $commands);
        $this->assertContains('atlas ai voice scripted-smoke --json', $commands);
        $this->assertContains('atlas ai voice callback-smoke --json', $commands);
        $this->assertContains('atlas ai voice callback-sequence-smoke --json', $commands);
        $this->assertContains('atlas ai voice callback-loop-check --json', $commands);
        $this->assertContains('atlas ai voice preflight --json', $commands);
        $this->assertContains('atlas ai voice activation-contract --json', $commands);
        $this->assertContains('atlas ai voice sdk-check --json', $commands);
        $this->assertContains('atlas ai voice worker-plan --json', $commands);
        $this->assertContains('atlas ai voice production-loop-plan --json', $commands);
        $this->assertContains('atlas ai voice production-loop-smoke --json', $commands);
        $this->assertContains('atlas ai voice worker-start-check --json', $commands);
        $this->assertContains('atlas ai voice runtime-certify --json', $commands);
        $this->assertContains('atlas ai voice readiness --hours=24 --json', $commands);
        $this->assertContains('atlas ai voice rivals --hours=24 --json', $commands);
        $this->assertContains('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $commands);
        $this->assertContains('atlas ai kernel-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai repair-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai provider-performance --hours=24 --json', $commands);
        $this->assertContains('atlas ai agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_release_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('atlas ai qualitative-levels --hours=720 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy report --hours=8760 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy due-reviews --due-days=30 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json', $commands);
        $this->assertContains('atlas ai strategic-decision review --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('atlas ai ledger-project --limit=500 --json', $commands);
        $this->assertContains('atlas ai self-improvement-schedule-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertSame('architecture_operations', data_get($summary, 'commands.0.id'));
        $this->assertSame('cli', data_get($summary, 'commands.0.surface'));
        $this->assertSame('provider_evolution', data_get($summary, 'commands.10.kind'));
        $this->assertSame('maintenance', data_get($summary, 'commands.11.kind'));

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
        $this->assertSame('maintenance', data_get($commandsById, 'knowledge_sync.kind'));
        $this->assertSame('surface_contract', data_get($commandsById, 'voice_realtime_contract.kind'));
        $this->assertSame('surface_contract', data_get($commandsById, 'voice_realtime_bootstrap.kind'));
        $this->assertSame('/ai/voice/runtime/contract', data_get($commandsById, 'voice_realtime_contract.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/contract', data_get($commandsById, 'voice_realtime_contract.mobile_endpoint'));
        $this->assertSame('/ai/voice/runtime/bootstrap', data_get($commandsById, 'voice_realtime_bootstrap.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/bootstrap', data_get($commandsById, 'voice_realtime_bootstrap.mobile_endpoint'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_scripted_smoke.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_callback_smoke.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_callback_sequence_smoke.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_callback_loop_check.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_preflight.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_activation_contract.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_worker_plan.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_production_loop_plan.kind'));
        $this->assertSame('validation', data_get($commandsById, 'voice_realtime_production_loop_smoke.kind'));
        $this->assertSame('runtime_contract', data_get($commandsById, 'voice_realtime_worker_start_check.kind'));
        $this->assertSame('maturity_report', data_get($commandsById, 'voice_realtime_rivals_report.kind'));
    }

    public function test_catalog_filters_architecture_operations_by_id_and_kind(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);

        $byId = $catalog->summary(['id' => 'provider_performance_report']);
        $byKind = $catalog->summary(['kind' => 'evidence_report']);

        $this->assertSame(['id' => 'provider_performance_report'], $byId['filters']);
        $this->assertSame(1, $byId['command_count']);
        $this->assertSame(['provider_performance_report'], $byId['operation_ids']);
        $this->assertSame('atlas ai provider-performance --hours=24 --json', data_get($byId, 'commands.0.command'));

        $byValidation = $catalog->summary(['kind' => 'validation']);
        $this->assertSame(8, $byValidation['command_count']);
        $this->assertSame([
            'architecture_validate',
            'documentation_health',
            'voice_realtime_scripted_smoke',
            'voice_realtime_callback_smoke',
            'voice_realtime_callback_sequence_smoke',
            'voice_realtime_preflight',
            'voice_realtime_production_loop_smoke',
            'voice_realtime_runtime_certification',
        ], $byValidation['operation_ids']);

        $byMaintenance = $catalog->summary(['kind' => 'maintenance']);
        $this->assertSame(3, $byMaintenance['command_count']);
        $this->assertSame(['knowledge_sync', 'code_intelligence_index', 'ledger_projection_worker'], $byMaintenance['operation_ids']);

        $byBootstrap = $catalog->summary(['kind' => 'bootstrap']);
        $this->assertSame(1, $byBootstrap['command_count']);
        $this->assertSame(['session_bootstrap'], $byBootstrap['operation_ids']);

        $byGovernanceGate = $catalog->summary(['kind' => 'governance_gate']);
        $this->assertSame(3, $byGovernanceGate['command_count']);
        $this->assertSame(['feature_placement', 'documentation_split_plan', 'ap_agent_workflow_registry'], $byGovernanceGate['operation_ids']);

        $byProviderProjection = $catalog->summary(['kind' => 'provider_projection']);
        $this->assertSame(2, $byProviderProjection['command_count']);
        $this->assertSame(['provider_projection_status', 'provider_projection_write'], $byProviderProjection['operation_ids']);

        $byProviderEvolution = $catalog->summary(['kind' => 'provider_evolution']);
        $this->assertSame(1, $byProviderEvolution['command_count']);
        $this->assertSame(['provider_release_review'], $byProviderEvolution['operation_ids']);
        $this->assertSame('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', data_get($byProviderEvolution, 'commands.0.command'));

        $bySurfaceContract = $catalog->summary(['kind' => 'surface_contract']);
        $this->assertSame(2, $bySurfaceContract['command_count']);
        $this->assertSame(['voice_realtime_contract', 'voice_realtime_bootstrap'], $bySurfaceContract['operation_ids']);

        $byRuntimeContract = $catalog->summary(['kind' => 'runtime_contract']);
        $this->assertSame(9, $byRuntimeContract['command_count']);
        $this->assertSame(['voice_realtime_dependencies', 'voice_realtime_scripted_worker_example', 'voice_realtime_callback_loop_check', 'voice_realtime_activation_contract', 'voice_realtime_sdk_check', 'voice_realtime_worker_plan', 'voice_realtime_production_loop_plan', 'voice_realtime_worker_start_check', 'voice_python_runtime_contract_test'], $byRuntimeContract['operation_ids']);

        $this->assertSame(['kind' => 'evidence_report'], $byKind['filters']);
        $this->assertSame(12, $byKind['command_count']);
        $this->assertNotContains('architecture_operations', $byKind['operation_ids']);
        $this->assertContains('voice_realtime_readiness', $byKind['operation_ids']);
        $this->assertContains('kernel_slo_report', $byKind['operation_ids']);
        $this->assertContains('agent_behavior_report', $byKind['operation_ids']);
        $this->assertContains('dynamic_compute_market_report', $byKind['operation_ids']);
        $this->assertContains('provider_cost_rates_missing', $byKind['operation_ids']);
        $this->assertContains('decision_receipt_report', $byKind['operation_ids']);
        $this->assertContains('ledger_replay', $byKind['operation_ids']);
        $this->assertContains('inbox_action_report', $byKind['operation_ids']);

        $byReadiness = $catalog->summary(['kind' => 'readiness']);
        $this->assertSame(1, $byReadiness['command_count']);
        $this->assertSame(['architecture_readiness'], $byReadiness['operation_ids']);
        $this->assertSame('php artisan atlas:ai:architecture-readiness --json', data_get($byReadiness, 'commands.0.command'));
        $this->assertSame('/ai/architecture/readiness', data_get($byReadiness, 'commands.0.api_endpoint'));

        $byPlanning = $catalog->summary(['kind' => 'planning_surface']);
        $this->assertSame(1, $byPlanning['command_count']);
        $this->assertSame(['strategic_decision_review'], $byPlanning['operation_ids']);
        $this->assertSame('atlas ai strategic-decision review --json', data_get($byPlanning, 'commands.0.command'));

        $byMaturity = $catalog->summary(['kind' => 'maturity_report']);
        $this->assertSame(3, $byMaturity['command_count']);
        $this->assertSame(['voice_realtime_rivals_report', 'qualitative_levels_report', 'rivals_strategy_report'], $byMaturity['operation_ids']);
        $this->assertSame('atlas ai voice rivals --hours=24 --json', data_get($byMaturity, 'commands.0.command'));
        $this->assertSame('atlas ai qualitative-levels --hours=720 --json', data_get($byMaturity, 'commands.1.command'));
        $this->assertSame('atlas ai rivals-strategy report --hours=8760 --json', data_get($byMaturity, 'commands.2.command'));

        $byReviewQueue = $catalog->summary(['kind' => 'review_queue']);
        $this->assertSame(1, $byReviewQueue['command_count']);
        $this->assertSame(['rivals_strategy_due_reviews'], $byReviewQueue['operation_ids']);

        $byReviewAction = $catalog->summary(['kind' => 'review_action']);
        $this->assertSame(2, $byReviewAction['command_count']);
        $this->assertSame(['provider_cost_rates_upsert', 'rivals_strategy_record_review'], $byReviewAction['operation_ids']);

        $byCuratorReview = $catalog->summary(['kind' => 'curator_review']);
        $this->assertSame(4, $byCuratorReview['command_count']);
        $this->assertSame(['provider_performance_curator_review', 'provider_release_curator_review', 'agent_behavior_curator_review', 'voice_realtime_curator_review'], $byCuratorReview['operation_ids']);
        $this->assertSame('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', data_get($byCuratorReview, 'commands.0.command'));
        $this->assertSame('atlas ai self-improve --flow=provider_release_review --hours=168 --json', data_get($byCuratorReview, 'commands.1.command'));
        $this->assertSame('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', data_get($byCuratorReview, 'commands.2.command'));
        $this->assertSame('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', data_get($byCuratorReview, 'commands.3.command'));

        $byCostRateAction = $catalog->summary(['id' => 'provider_cost_rates_upsert']);
        $this->assertSame(1, $byCostRateAction['command_count']);
        $this->assertSame('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', data_get($byCostRateAction, 'commands.0.command'));

        $byDynamicComputeMarket = $catalog->summary(['id' => 'dynamic_compute_market_report']);
        $this->assertSame(1, $byDynamicComputeMarket['command_count']);
        $this->assertSame('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', data_get($byDynamicComputeMarket, 'commands.0.command'));

        $byVoiceReadiness = $catalog->summary(['id' => 'voice_realtime_readiness']);
        $this->assertSame(1, $byVoiceReadiness['command_count']);
        $this->assertSame('atlas ai voice readiness --hours=24 --json', data_get($byVoiceReadiness, 'commands.0.command'));
        $this->assertSame('evidence_report', data_get($byVoiceReadiness, 'commands.0.kind'));
        $this->assertSame('/ai/voice/readiness', data_get($byVoiceReadiness, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/readiness', data_get($byVoiceReadiness, 'commands.0.mobile_endpoint'));

        $byVoiceRivals = $catalog->summary(['id' => 'voice_realtime_rivals_report']);
        $this->assertSame(1, $byVoiceRivals['command_count']);
        $this->assertSame('atlas ai voice rivals --hours=24 --json', data_get($byVoiceRivals, 'commands.0.command'));
        $this->assertSame('maturity_report', data_get($byVoiceRivals, 'commands.0.kind'));
        $this->assertSame('/ai/voice/rivals', data_get($byVoiceRivals, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/rivals', data_get($byVoiceRivals, 'commands.0.mobile_endpoint'));

        $byVoiceScriptedSmoke = $catalog->summary(['id' => 'voice_realtime_scripted_smoke']);
        $this->assertSame(1, $byVoiceScriptedSmoke['command_count']);
        $this->assertSame('atlas ai voice scripted-smoke --json', data_get($byVoiceScriptedSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceScriptedSmoke, 'commands.0.kind'));

        $byVoiceCallbackSmoke = $catalog->summary(['id' => 'voice_realtime_callback_smoke']);
        $this->assertSame(1, $byVoiceCallbackSmoke['command_count']);
        $this->assertSame('atlas ai voice callback-smoke --json', data_get($byVoiceCallbackSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceCallbackSmoke, 'commands.0.kind'));

        $byVoiceCallbackSequenceSmoke = $catalog->summary(['id' => 'voice_realtime_callback_sequence_smoke']);
        $this->assertSame(1, $byVoiceCallbackSequenceSmoke['command_count']);
        $this->assertSame('atlas ai voice callback-sequence-smoke --json', data_get($byVoiceCallbackSequenceSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceCallbackSequenceSmoke, 'commands.0.kind'));

        $byVoiceCallbackLoopCheck = $catalog->summary(['id' => 'voice_realtime_callback_loop_check']);
        $this->assertSame(1, $byVoiceCallbackLoopCheck['command_count']);
        $this->assertSame('atlas ai voice callback-loop-check --json', data_get($byVoiceCallbackLoopCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceCallbackLoopCheck, 'commands.0.kind'));

        $byVoicePreflight = $catalog->summary(['id' => 'voice_realtime_preflight']);
        $this->assertSame(1, $byVoicePreflight['command_count']);
        $this->assertSame('atlas ai voice preflight --json', data_get($byVoicePreflight, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoicePreflight, 'commands.0.kind'));

        $byVoiceActivationContract = $catalog->summary(['id' => 'voice_realtime_activation_contract']);
        $this->assertSame(1, $byVoiceActivationContract['command_count']);
        $this->assertSame('atlas ai voice activation-contract --json', data_get($byVoiceActivationContract, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceActivationContract, 'commands.0.kind'));

        $byVoiceSdkCheck = $catalog->summary(['id' => 'voice_realtime_sdk_check']);
        $this->assertSame(1, $byVoiceSdkCheck['command_count']);
        $this->assertSame('atlas ai voice sdk-check --json', data_get($byVoiceSdkCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceSdkCheck, 'commands.0.kind'));

        $byVoiceWorkerPlan = $catalog->summary(['id' => 'voice_realtime_worker_plan']);
        $this->assertSame(1, $byVoiceWorkerPlan['command_count']);
        $this->assertSame('atlas ai voice worker-plan --json', data_get($byVoiceWorkerPlan, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceWorkerPlan, 'commands.0.kind'));

        $byVoiceProductionLoopPlan = $catalog->summary(['id' => 'voice_realtime_production_loop_plan']);
        $this->assertSame(1, $byVoiceProductionLoopPlan['command_count']);
        $this->assertSame('atlas ai voice production-loop-plan --json', data_get($byVoiceProductionLoopPlan, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceProductionLoopPlan, 'commands.0.kind'));

        $byVoiceProductionLoopSmoke = $catalog->summary(['id' => 'voice_realtime_production_loop_smoke']);
        $this->assertSame(1, $byVoiceProductionLoopSmoke['command_count']);
        $this->assertSame('atlas ai voice production-loop-smoke --json', data_get($byVoiceProductionLoopSmoke, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceProductionLoopSmoke, 'commands.0.kind'));

        $byVoiceWorkerStartCheck = $catalog->summary(['id' => 'voice_realtime_worker_start_check']);
        $this->assertSame(1, $byVoiceWorkerStartCheck['command_count']);
        $this->assertSame('atlas ai voice worker-start-check --json', data_get($byVoiceWorkerStartCheck, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceWorkerStartCheck, 'commands.0.kind'));

        $byVoiceRuntimeCertification = $catalog->summary(['id' => 'voice_realtime_runtime_certification']);
        $this->assertSame(1, $byVoiceRuntimeCertification['command_count']);
        $this->assertSame('atlas ai voice runtime-certify --json', data_get($byVoiceRuntimeCertification, 'commands.0.command'));
        $this->assertSame('validation', data_get($byVoiceRuntimeCertification, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/certification', data_get($byVoiceRuntimeCertification, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/certification', data_get($byVoiceRuntimeCertification, 'commands.0.mobile_endpoint'));

        $byVoiceDependencies = $catalog->summary(['id' => 'voice_realtime_dependencies']);
        $this->assertSame(1, $byVoiceDependencies['command_count']);
        $this->assertSame('atlas ai voice dependencies --json', data_get($byVoiceDependencies, 'commands.0.command'));
        $this->assertSame('runtime_contract', data_get($byVoiceDependencies, 'commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/dependencies', data_get($byVoiceDependencies, 'commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependencies', data_get($byVoiceDependencies, 'commands.0.mobile_endpoint'));
    }
}
