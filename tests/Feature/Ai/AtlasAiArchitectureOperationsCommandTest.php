<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiArchitectureOperationsCommandTest extends TestCase
{
    public function test_command_exposes_architecture_operations_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.architecture_operations.v1', data_get($payload, 'architecture_operations.schema_version'));
        $this->assertSame('arquitetura_mae', data_get($payload, 'architecture_operations.section'));
        $this->assertSame(82, data_get($payload, 'architecture_operations.command_count'));
        $this->assertContains('architecture_operations', data_get($payload, 'architecture_operations.operation_ids'));

        $commands = array_column(data_get($payload, 'architecture_operations.commands'), 'command');

        $this->assertContains('php artisan atlas:ai:architecture-operations --json', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-validate', $commands);
        $this->assertContains('php artisan atlas:ai:architecture-readiness --json', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('php artisan atlas:ai:session-bootstrap --task="<task>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:place-feature "<feature>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:docs-split-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:ap-agent-workflow --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json', $commands);
        $this->assertContains('php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $commands);
        $this->assertContains('php artisan atlas:ai:provider-release-sources --json', $commands);
        $this->assertContains('php artisan atlas:ai:external-graph-harness --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --schedule-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-report --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --emit-rivals-shadow-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json', $commands);
        $this->assertContains('php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json', $commands);
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
        $this->assertContains('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $commands);
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
        $this->assertContains('php artisan atlas:ai:ledger-project --limit=500 --json', $commands);
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', $commands);
        $this->assertSame('architecture_operations', data_get($payload, 'architecture_operations.commands.0.id'));
        $this->assertSame('catalog', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('cli', data_get($payload, 'architecture_operations.commands.0.surface'));
        $this->assertSame('architecture_readiness', data_get($payload, 'architecture_operations.commands.2.id'));
        $this->assertSame('readiness', data_get($payload, 'architecture_operations.commands.2.kind'));
        $this->assertSame('/ai/architecture/readiness', data_get($payload, 'architecture_operations.commands.2.api_endpoint'));

        $commandsById = collect(data_get($payload, 'architecture_operations.commands'))->keyBy('id');
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
        $this->assertSame('session_bootstrap_blocked_by_strict_gate', data_get($commandsById, 'session_bootstrap.strict_gate.mcp_error'));
        $this->assertSame('php artisan atlas:ai:place-feature "<feature>" --strict --json', data_get($commandsById, 'feature_placement.strict_command'));
        $this->assertSame('feature_placement_blocked_by_strict_gate', data_get($commandsById, 'feature_placement.strict_gate.mcp_error'));
        $this->assertSame('atlas.voice_realtime.python_runtime_plan.v1', data_get($commandsById, 'voice_realtime_dependencies.runtime_dependency_contract'));
        $this->assertSame('ATLAS_VOICE_PYTHON_BIN', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.environment_variable'));
        $this->assertSame('atlas_ai.voice_realtime.python_binary', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.config_key'));
        $this->assertSame('3.10', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.minimum_version'));
        $this->assertSame('3.11', data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.recommended_version'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.pre_implementation_gate'));
        $this->assertTrue(data_get($commandsById, 'voice_realtime_dependencies.python_binary_policy.fail_closed_when_missing'));
        $this->assertContains('next_action', data_get($commandsById, 'voice_realtime_dependencies.output_contract.python_runtime'));
        $this->assertSame('atlas.voice_realtime.dependency_install_plan.v1', data_get($commandsById, 'voice_realtime_dependency_install_plan.runtime_dependency_contract'));
        $this->assertFalse(data_get($commandsById, 'voice_realtime_dependency_install_plan.operator_managed_policy.auto_install'));
        $this->assertContains('requirements_sha256', data_get($commandsById, 'voice_realtime_dependency_install_plan.output_contract.required_fields'));
    }

    public function test_command_human_output_lists_architecture_operations(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-operations');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Architecture Operations', $output);
        $this->assertStringContainsString('php artisan atlas:ai:architecture-operations --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:architecture-readiness --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge docs-health --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:session-bootstrap --task="<task>" --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:place-feature "<feature>" --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:docs-split-plan --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:ap-agent-workflow --json', $output);
        $this->assertStringContainsString('php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json', $output);
        $this->assertStringContainsString('php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:external-graph-harness --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:local-rag-benchmark --schedule-plan --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:local-rag-benchmark --rivals-report --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:local-rag-benchmark --emit-external-vector-rag-preflight-inbox --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge sync --prune --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge index-code --prune --summary-only --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:provider-performance --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:agent-behavior-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice contract --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice bootstrap --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice dependencies --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice scripted-example --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice scripted-smoke --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice callback-smoke --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice callback-sequence-smoke --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice callback-loop-check --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice preflight --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice activation-contract --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice sdk-check --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice worker-plan --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice production-loop-plan --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice product-loop-check --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice production-loop-smoke --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice worker-start-check --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice runtime-certify --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice readiness --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice rivals --hours=24 --json', $output);
        $this->assertStringContainsString('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $output);
        $this->assertStringContainsString('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:capture-inbox-pipeline-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:task-orchestration-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:task-orchestration-backfill-receipts --hours=720 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:tool-action-runtime-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:long-running-work-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:proactive-layer-report --hours=24 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:decision-receipt-report --envelope=<id> --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:ledger <id> --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:ledger-project --limit=500 --json', $output);
        $this->assertStringContainsString('php artisan atlas:ai:runtime-boundary --json', $output);
    }

    public function test_command_filters_architecture_operations_by_id_and_kind(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--kind' => 'evidence_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['kind' => 'evidence_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(17, data_get($payload, 'architecture_operations.command_count'));
        $this->assertContains('voice_realtime_readiness', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_performance_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('agent_behavior_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('dynamic_compute_market_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_cost_rates_missing', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('capture_inbox_pipeline_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('task_orchestration_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('tool_action_runtime_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('long_running_work_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('proactive_layer_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', data_get($payload, 'architecture_operations.operation_ids'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--section' => 'arquitetura_mae',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['section' => 'arquitetura_mae'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(82, data_get($payload, 'architecture_operations.command_count'));
        $this->assertContains('architecture_operations', data_get($payload, 'architecture_operations.operation_ids'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--surface' => 'runtime',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['surface' => 'runtime'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame(['voice_python_runtime_contract_test'], data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame(
            'PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests',
            data_get($payload, 'architecture_operations.commands.0.command'),
        );

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'inbox_action_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'inbox_action_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:inbox-action-report --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'runtime_language_boundary',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'runtime_language_boundary'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:runtime-boundary --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('runtime', data_get($payload, 'architecture_operations.commands.0.owner_layer'));
        $this->assertTrue(data_get($payload, 'architecture_operations.commands.0.pre_implementation_gate'));
        $this->assertSame(['python_ai_data', 'go_edge', 'swift_native_mac'], data_get($payload, 'architecture_operations.commands.0.governed_runtimes'));
        $this->assertContains('python', data_get($payload, 'architecture_operations.commands.0.required_for_terms'));
        $this->assertSame('/ai/runtime-boundary', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('atlas_runtime_boundary', data_get($payload, 'architecture_operations.commands.0.mcp_tool'));
        $this->assertSame('ap201_runtime_language_boundary_contract', data_get($payload, 'architecture_operations.commands.0.scan_id'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--owner-layer' => 'runtime',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['owner_layer' => 'runtime'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame(['runtime_language_boundary'], data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame('php artisan atlas:ai:runtime-boundary --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'provider_cost_rates_upsert',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'provider_cost_rates_upsert'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'dynamic_compute_market_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'dynamic_compute_market_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'architecture_readiness',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'architecture_readiness'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:architecture-readiness --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('readiness', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('/ai/architecture/readiness', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--kind' => 'provider_evolution',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['kind' => 'provider_evolution'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(2, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame(['provider_release_review', 'provider_release_sources'], data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertSame('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('php artisan atlas:ai:provider-release-sources --json', data_get($payload, 'architecture_operations.commands.1.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'provider_performance_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'provider_performance_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'provider_release_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'provider_release_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'agent_behavior_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'agent_behavior_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_readiness',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_readiness'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice readiness --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('/ai/voice/readiness', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/readiness', data_get($payload, 'architecture_operations.commands.0.mobile_endpoint'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_rivals_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_rivals_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice rivals --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('maturity_report', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('/ai/voice/rivals', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/rivals', data_get($payload, 'architecture_operations.commands.0.mobile_endpoint'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_scripted_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_scripted_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice scripted-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice callback-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_sequence_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_sequence_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice callback-sequence-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_loop_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_loop_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice callback-loop-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_preflight',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_preflight'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice preflight --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_activation_contract',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_activation_contract'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice activation-contract --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_sdk_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_sdk_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice sdk-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_worker_plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_worker_plan'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice worker-plan --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_production_loop_plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_production_loop_plan'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice production-loop-plan --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_product_loop_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_product_loop_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice product-loop-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_production_loop_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_production_loop_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice production-loop-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_worker_start_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_worker_start_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice worker-start-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_runtime_certification',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_runtime_certification'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice runtime-certify --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/certification', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/certification', data_get($payload, 'architecture_operations.commands.0.mobile_endpoint'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_dependencies',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_dependencies'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice dependencies --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/dependencies', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependencies', data_get($payload, 'architecture_operations.commands.0.mobile_endpoint'));
    }
}
