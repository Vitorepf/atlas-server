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
        $this->assertSame(45, data_get($payload, 'architecture_operations.command_count'));
        $this->assertContains('architecture_operations', data_get($payload, 'architecture_operations.operation_ids'));

        $commands = array_column(data_get($payload, 'architecture_operations.commands'), 'command');

        $this->assertContains('atlas ai architecture-operations --json', $commands);
        $this->assertContains('atlas ai architecture-validate', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
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
        $this->assertContains('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $commands);
        $this->assertContains('atlas ai agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('atlas ai ledger-project --limit=500 --json', $commands);
        $this->assertSame('architecture_operations', data_get($payload, 'architecture_operations.commands.0.id'));
        $this->assertSame('catalog', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('cli', data_get($payload, 'architecture_operations.commands.0.surface'));
    }

    public function test_command_human_output_lists_architecture_operations(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-operations');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Architecture Operations', $output);
        $this->assertStringContainsString('atlas ai architecture-operations --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge docs-health --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge sync --prune --json', $output);
        $this->assertStringContainsString('atlas engineering knowledge index-code --prune --json', $output);
        $this->assertStringContainsString('atlas ai provider-performance --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai agent-behavior-report --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $output);
        $this->assertStringContainsString('atlas ai voice contract --json', $output);
        $this->assertStringContainsString('atlas ai voice bootstrap --json', $output);
        $this->assertStringContainsString('atlas ai voice dependencies --json', $output);
        $this->assertStringContainsString('atlas ai voice scripted-example --json', $output);
        $this->assertStringContainsString('atlas ai voice scripted-smoke --json', $output);
        $this->assertStringContainsString('atlas ai voice callback-smoke --json', $output);
        $this->assertStringContainsString('atlas ai voice callback-sequence-smoke --json', $output);
        $this->assertStringContainsString('atlas ai voice callback-loop-check --json', $output);
        $this->assertStringContainsString('atlas ai voice preflight --json', $output);
        $this->assertStringContainsString('atlas ai voice activation-contract --json', $output);
        $this->assertStringContainsString('atlas ai voice sdk-check --json', $output);
        $this->assertStringContainsString('atlas ai voice worker-plan --json', $output);
        $this->assertStringContainsString('atlas ai voice production-loop-plan --json', $output);
        $this->assertStringContainsString('atlas ai voice production-loop-smoke --json', $output);
        $this->assertStringContainsString('atlas ai voice worker-start-check --json', $output);
        $this->assertStringContainsString('atlas ai voice runtime-certify --json', $output);
        $this->assertStringContainsString('atlas ai voice readiness --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai voice rivals --hours=24 --json', $output);
        $this->assertStringContainsString('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai telemetry cost-rates --missing --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $output);
        $this->assertStringContainsString('atlas ai decision-receipt-report --envelope=<id> --json', $output);
        $this->assertStringContainsString('atlas ledger replay --envelope=<id> --json', $output);
        $this->assertStringContainsString('atlas ai ledger-project --limit=500 --json', $output);
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
        $this->assertSame(12, data_get($payload, 'architecture_operations.command_count'));
        $this->assertContains('voice_realtime_readiness', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_performance_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('agent_behavior_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('dynamic_compute_market_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_cost_rates_missing', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', data_get($payload, 'architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', data_get($payload, 'architecture_operations.operation_ids'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'inbox_action_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'inbox_action_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai inbox-action-report --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'provider_cost_rates_upsert',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'provider_cost_rates_upsert'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'dynamic_compute_market_report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'dynamic_compute_market_report'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'provider_performance_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'provider_performance_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'agent_behavior_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'agent_behavior_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_curator_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_curator_review'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai self-improve --flow=voice_realtime_review --hours=168 --json', data_get($payload, 'architecture_operations.commands.0.command'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_readiness',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_readiness'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice readiness --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));
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
        $this->assertSame('atlas ai voice rivals --hours=24 --json', data_get($payload, 'architecture_operations.commands.0.command'));
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
        $this->assertSame('atlas ai voice scripted-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice callback-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_sequence_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_sequence_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice callback-sequence-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_callback_loop_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_callback_loop_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice callback-loop-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_preflight',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_preflight'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice preflight --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_activation_contract',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_activation_contract'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice activation-contract --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_sdk_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_sdk_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice sdk-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_worker_plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_worker_plan'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice worker-plan --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_production_loop_plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_production_loop_plan'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice production-loop-plan --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_production_loop_smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_production_loop_smoke'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice production-loop-smoke --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('validation', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_worker_start_check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_worker_start_check'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice worker-start-check --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));

        $exit = Artisan::call('atlas:ai:architecture-operations', [
            '--id' => 'voice_realtime_runtime_certification',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['id' => 'voice_realtime_runtime_certification'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($payload, 'architecture_operations.command_count'));
        $this->assertSame('atlas ai voice runtime-certify --json', data_get($payload, 'architecture_operations.commands.0.command'));
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
        $this->assertSame('atlas ai voice dependencies --json', data_get($payload, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($payload, 'architecture_operations.commands.0.kind'));
        $this->assertSame('/ai/voice/runtime/dependencies', data_get($payload, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependencies', data_get($payload, 'architecture_operations.commands.0.mobile_endpoint'));
    }
}
