<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

class AiObservabilityKernelSloTest extends TestCase
{
    use CreatesAiJobChoiceTables;

    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Cache::flush();
        $this->createAiJobChoiceTables();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Schema::dropIfExists('atlas_ledger_events');
        $this->dropAiJobChoiceTables();

        parent::tearDown();
    }

    public function test_observability_payload_includes_kernel_slo_window_summary(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HOBSERVABILITYSLO00000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_observability',
            'operator_id' => 'operator_observability',
            'envelope_id' => 'env_observability_slo',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_observability_slo',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
                'dimensions' => [
                    'domain' => 'programming',
                    'surface_id' => 'atlas_cli_dev',
                    'provider' => 'codex_cli',
                    'model' => 'gpt-5.2',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 300000,
                    'success' => false,
                    'status' => 'breach',
                    'severity' => 'high',
                    'violations' => ['stage_failed'],
                ],
            ],
            'payload_hash' => hash('sha256', 'observability-slo'),
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('kernel_slo.available', true)
            ->assertJsonPath('kernel_slo.observation_count', 1)
            ->assertJsonPath('kernel_slo.envelope_count', 1)
            ->assertJsonPath('kernel_slo.worst_status', 'breach')
            ->assertJsonPath('kernel_slo.dimensions.domain.programming', 1)
            ->assertJsonPath('kernel_slo.dimensions.provider.codex_cli', 1)
            ->assertJsonPath('kernel_slo.recent_breaches.0.envelope_id', 'env_observability_slo');

        $this->assertSame(300000, $response->json('kernel_slo.stages')['runtime.execute']['p95_ms']);
        $this->assertSame(['stage_failed'], $response->json('kernel_slo.stages')['runtime.execute']['violations']);
        $this->assertSame(['atlas_cli_dev' => 1], $response->json('kernel_slo.stages')['runtime.execute']['dimensions']['surface_id']);
    }

    public function test_observability_payload_includes_kernel_repair_window_summary(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HOBSERVABILITYREPAIR0000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_observability',
            'operator_id' => 'operator_observability',
            'envelope_id' => 'env_observability_repair',
            'receipt_id' => 'receipt_observability_repair',
            'trace_id' => null,
            'correlation_id' => 'env_observability_repair',
            'causation_id' => null,
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'atlas.repair.v1',
            'payload' => [
                'failure_classification' => [
                    'failure_domain' => 'compliance.violation',
                ],
                'decision' => [
                    'status' => 'needs_human_review',
                    'strategy' => 'human_review',
                    'reasons' => ['failure_domain_requires_human_review'],
                ],
                'repair_executed' => false,
                'decision_hash' => 'observability-repair-decision',
            ],
            'payload_hash' => hash('sha256', 'observability-repair'),
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('kernel_repair.available', true)
            ->assertJsonPath('kernel_repair.repair_event_count', 1)
            ->assertJsonPath('kernel_repair.envelope_count', 1)
            ->assertJsonPath('kernel_repair.latest_status', 'needs_human_review')
            ->assertJsonPath('kernel_repair.latest_strategy', 'human_review')
            ->assertJsonPath('kernel_repair.requires_human_review', true)
            ->assertJsonPath('kernel_repair.recent_events.0.envelope_id', 'env_observability_repair');

        $this->assertSame(['human_review' => 1], $response->json('kernel_repair.strategy_counts'));
        $this->assertSame(['failure_domain_requires_human_review' => 1], $response->json('kernel_repair.reason_counts'));
    }

    public function test_observability_payload_includes_self_improvement_schedule(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('self_improvement_schedule.enabled', true)
            ->assertJsonPath('self_improvement_schedule.schedulable', true)
            ->assertJsonPath('self_improvement_schedule.scheduler_registration.status', 'registered')
            ->assertJsonPath('self_improvement_schedule.scheduler_registration.registered_command_count', 2)
            ->assertJsonPath('self_improvement_schedule.plan_hash_algorithm', 'sha256')
            ->assertJsonPath('self_improvement_schedule.time', '02:00')
            ->assertJsonPath('self_improvement_schedule.timezone', config('app.timezone'))
            ->assertJsonStructure(['self_improvement_schedule' => ['next_run_at']])
            ->assertJsonPath('self_improvement_schedule.count', 2)
            ->assertJsonPath('self_improvement_schedule.configured_flows.0', 'nightly_review')
            ->assertJsonPath('self_improvement_schedule.configured_flows.1', 'repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.invalid_flows', [])
            ->assertJsonPath('self_improvement_schedule.defaulted', false)
            ->assertJsonPath('self_improvement_schedule.health.status', 'healthy')
            ->assertJsonPath('self_improvement_schedule.flows.0', 'nightly_review')
            ->assertJsonPath('self_improvement_schedule.flows.1', 'repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.commands.1.command', 'atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json')
            ->assertJsonPath('self_improvement_schedule.emit', false);
    }

    public function test_observability_payload_reports_invalid_self_improvement_schedule_flows(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'self_improvement.repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('self_improvement_schedule.configured_flows.0', 'unknown_flow')
            ->assertJsonPath('self_improvement_schedule.configured_flows.1', 'self_improvement.repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.invalid_flows.0', 'unknown_flow')
            ->assertJsonPath('self_improvement_schedule.defaulted', false)
            ->assertJsonPath('self_improvement_schedule.health.status', 'warning')
            ->assertJsonPath('self_improvement_schedule.health.issues.0', 'invalid_self_improvement_flows_configured')
            ->assertJsonPath('self_improvement_schedule.flows.0', 'repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.commands.0.command', 'atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json');
    }
}
