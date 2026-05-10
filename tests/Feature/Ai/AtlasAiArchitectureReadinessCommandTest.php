<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiArchitectureReadinessCommandTest extends TestCase
{
    public function test_command_returns_architecture_readiness_snapshot_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-readiness', [
            '--owner' => 'kernel_architecture',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.architecture_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertIsBool(data_get($payload, 'summary.ready_for_implementation'));
        $this->assertSame(0, data_get($payload, 'summary.failed_static_scan_count'));
        $this->assertIsString(data_get($payload, 'summary.message'));
        $this->assertSame('ok', data_get($payload, 'checks.architecture_validate.status'));
        $this->assertSame('ok', data_get($payload, 'checks.documentation_health.status'));
        $this->assertContains(data_get($payload, 'checks.provider_projection.status'), ['passed', 'needs_review']);
        $this->assertSame('kernel_architecture', data_get($payload, 'docs_split_plan.owner'));
        $this->assertSame('php artisan atlas:ai:docs-split-plan --owner=kernel_architecture --json', data_get($payload, 'docs_split_plan.command'));
        $this->assertSame(
            'atlas.implemented_vs_scaffold.coverage_boundary.v1',
            data_get($payload, 'coverage_boundary.schema_version'),
        );
        $this->assertSame('available', data_get($payload, 'coverage_boundary.status'));
        $this->assertSame('diagnostic_read_model_only', data_get($payload, 'coverage_boundary.authority'));
        $this->assertGreaterThanOrEqual(5, count(data_get($payload, 'safe_next_blocks', [])));
        $this->assertSame(1, data_get($payload, 'safe_next_blocks.0.order'));
        $this->assertSame('Voice Realtime product loop', data_get($payload, 'safe_next_blocks.0.block'));
        $this->assertSame('atlas.architecture_operations.v1', data_get($payload, 'architecture_operations.schema_version'));
        $this->assertSame(['id' => 'architecture_readiness'], data_get($payload, 'architecture_operations.filters'));
        $this->assertSame('architecture_readiness', data_get($payload, 'architecture_operations.commands.0.id'));
        $this->assertContains('session_bootstrap', data_get($payload, 'architecture_operations.related_operation_ids'));
        $this->assertContains('feature_placement', data_get($payload, 'architecture_operations.related_operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($payload, 'architecture_operations.related_operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($payload, 'architecture_operations.related_operation_ids'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', collect(data_get($payload, 'architecture_operations.related_commands'))->pluck('command')->all());
        $this->assertSame(
            ['runtime_language_boundary'],
            data_get($payload, 'architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertSame(
            'php artisan atlas:ai:runtime-boundary --json',
            data_get($payload, 'architecture_operations.owner_layer_operations.runtime.commands.0.command'),
        );
        $this->assertContains('php artisan atlas:ai:docs-split-plan --owner=kernel_architecture --json', data_get($payload, 'review_signal.required_next_commands'));
        $this->assertStringContainsString('php artisan atlas:memory:projection status --target=all --workspace=', data_get($payload, 'provider_projection.recommended_command'));
    }

    public function test_command_human_output_lists_readiness_summary(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-readiness', [
            '--owner' => 'kernel_architecture',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Architecture Readiness', $output);
        $this->assertStringContainsString('Ready for implementation', $output);
        $this->assertStringContainsString('Architecture', $output);
        $this->assertStringContainsString('Provider projection', $output);
        $this->assertStringContainsString('Safe next blocks', $output);
        $this->assertStringContainsString('Voice Realtime product loop', $output);
        $this->assertStringContainsString('Comandos recomendados', $output);
    }
}
