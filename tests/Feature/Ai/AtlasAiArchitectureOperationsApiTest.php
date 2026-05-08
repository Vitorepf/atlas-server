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
            ->assertJsonPath('architecture_operations.command_count', 54)
            ->assertJsonPath('architecture_operations.commands.0.id', 'architecture_operations')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'catalog')
            ->assertJsonPath('architecture_operations.commands.0.surface', 'cli');

        $commands = array_column($response->json('architecture_operations.commands'), 'command');

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
        $this->assertContains('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $commands);
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
        $this->assertContains('atlas ai agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_release_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('architecture_operations', $response->json('architecture_operations.operation_ids'));
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
            ->assertJsonPath('architecture_operations.command_count', 12);

        $this->assertContains('voice_realtime_readiness', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('provider_performance_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('agent_behavior_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('dynamic_compute_market_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('provider_cost_rates_missing', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', $response->json('architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', $response->json('architecture_operations.operation_ids'));

        $this->getJson('/ai/architecture/operations?id=provider_performance_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_performance_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai provider-performance --hours=24 --json');

        $this->getJson('/ai/architecture/operations?id=provider_cost_rates_upsert', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_cost_rates_upsert')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json');

        $this->getJson('/ai/architecture/operations?id=dynamic_compute_market_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'dynamic_compute_market_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json');

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
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'provider_release_review')
            ->assertJsonPath('architecture_operations.commands.0.command', 'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/provider-release-review');

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
            ->assertJsonPath('architecture_operations.command_count', 3)
            ->assertJsonPath('architecture_operations.operation_ids.0', 'feature_placement')
            ->assertJsonPath('architecture_operations.operation_ids.1', 'documentation_split_plan')
            ->assertJsonPath('architecture_operations.operation_ids.2', 'ap_agent_workflow_registry')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/feature-placement')
            ->assertJsonPath('architecture_operations.commands.1.api_endpoint', '/ai/docs-split-plan')
            ->assertJsonPath('architecture_operations.commands.2.command', 'php artisan atlas:ai:ap-agent-workflow --json');

        $this->getJson('/ai/architecture/operations?id=provider_performance_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_performance_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai self-improve --flow=provider_performance_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=provider_release_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_release_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai self-improve --flow=provider_release_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=agent_behavior_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'agent_behavior_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai self-improve --flow=agent_behavior_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_curator_review', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_curator_review')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai self-improve --flow=voice_realtime_review --hours=168 --json');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_readiness')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice readiness --hours=24 --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'evidence_report')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/readiness')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/readiness');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_rivals_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_rivals_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice rivals --hours=24 --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'maturity_report')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/rivals')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/rivals');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_scripted_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_scripted_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice scripted-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice callback-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_sequence_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_sequence_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice callback-sequence-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_callback_loop_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_callback_loop_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice callback-loop-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_preflight', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_preflight')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice preflight --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_activation_contract', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_activation_contract')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice activation-contract --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_sdk_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_sdk_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice sdk-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_worker_plan', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_worker_plan')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice worker-plan --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_production_loop_plan', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_production_loop_plan')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice production-loop-plan --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_production_loop_smoke', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_production_loop_smoke')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice production-loop-smoke --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_worker_start_check', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_worker_start_check')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice worker-start-check --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_runtime_certification', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_runtime_certification')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice runtime-certify --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'validation')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/runtime/certification')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/runtime/certification');

        $this->getJson('/ai/architecture/operations?id=voice_realtime_dependencies', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'voice_realtime_dependencies')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai voice dependencies --json')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'runtime_contract')
            ->assertJsonPath('architecture_operations.commands.0.api_endpoint', '/ai/voice/runtime/dependencies')
            ->assertJsonPath('architecture_operations.commands.0.mobile_endpoint', '/v1/mobile/ai/voice/runtime/dependencies');
    }
}
