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
        $this->assertSame(28, data_get($payload, 'architecture_operations.command_count'));
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
        $this->assertContains('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $commands);
        $this->assertContains('atlas ai agent-behavior-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $commands);
        $this->assertContains('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $commands);
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
        $this->assertStringContainsString('PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $output);
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
        $this->assertSame(11, data_get($payload, 'architecture_operations.command_count'));
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
    }
}
