<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
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

    public function test_observability_payload_uses_default_replay_window_contract(): void
    {
        $response = $this->getJson('/ai/observability', $this->headers)
            ->assertOk();

        $since = CarbonImmutable::parse($response->json('window.since'));
        $until = CarbonImmutable::parse($response->json('window.until'));

        $this->assertEqualsWithDelta(1440, $since->diffInMinutes($until), 1);
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
            ->assertJsonPath('kernel_slo.review_signal.status', 'breach')
            ->assertJsonPath('kernel_slo.review_signal.severity', 'high')
            ->assertJsonPath('kernel_slo.review_signal.recommended_action', 'open_reviewable_slo_regression_proposal')
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
            ->assertJsonPath('kernel_repair.review_signal.status', 'warning')
            ->assertJsonPath('kernel_repair.review_signal.severity', 'medium')
            ->assertJsonPath('kernel_repair.review_signal.recommended_action', 'open_reviewable_repair_loop_human_review_proposal')
            ->assertJsonPath('kernel_repair.recent_events.0.envelope_id', 'env_observability_repair');

        $this->assertSame(['human_review' => 1], $response->json('kernel_repair.strategy_counts'));
        $this->assertSame(['failure_domain_requires_human_review' => 1], $response->json('kernel_repair.reason_counts'));
    }

    public function test_observability_payload_includes_kernel_pipeline_window_summary(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HOBSERVABILITYPIPELINE000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_observability',
            'operator_id' => 'operator_observability',
            'envelope_id' => 'env_observability_pipeline',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_observability_pipeline',
            'causation_id' => null,
            'event_type' => LedgerEventType::KernelPipelineRejected->value,
            'emitter_stage' => 'atlas.kernel.pipeline',
            'emitter_version' => 'atlas.kernel.pipeline.v1',
            'payload' => [
                'status' => 'rejected',
                'pipeline' => [
                    'pipeline_id' => 'atlas.run.kernel_pipeline.v1',
                    'schema_version' => 'atlas.kernel_pipeline.v1',
                    'mode' => 'scaffold',
                    'stage_count' => 10,
                    'canonical_flow_hash' => hash('sha256', 'observability-pipeline-flow'),
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                ],
                'surface' => [
                    'surface_id' => 'atlas_ai_chat',
                    'binding_surface' => 'atlas:ai:chat',
                    'command' => 'atlas:ai:chat --dev',
                    'input_mode' => 'declared_dev_plan',
                ],
                'routing' => [
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'runtime' => 'scaffold',
                ],
                'violations' => ['provider_execution_allowed_must_be_false'],
            ],
            'payload_hash' => hash('sha256', 'observability-pipeline'),
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('kernel_pipeline.available', true)
            ->assertJsonPath('kernel_pipeline.kernel_pipeline_event_count', 1)
            ->assertJsonPath('kernel_pipeline.envelope_count', 1)
            ->assertJsonPath('kernel_pipeline.rejected_count', 1)
            ->assertJsonPath('kernel_pipeline.latest_status', 'rejected')
            ->assertJsonPath('kernel_pipeline.has_rejections', true)
            ->assertJsonPath('kernel_pipeline.health.status', 'breach')
            ->assertJsonPath('kernel_pipeline.health.rejection_rate', 1)
            ->assertJsonPath('kernel_pipeline.health.review_required', true)
            ->assertJsonPath('kernel_pipeline.review_signal.status', 'breach')
            ->assertJsonPath('kernel_pipeline.review_signal.severity', 'high')
            ->assertJsonPath('kernel_pipeline.review_signal.recommended_action', 'open_reviewable_kernel_pipeline_contract_proposal')
            ->assertJsonPath('kernel_pipeline.recent_events.0.envelope_id', 'env_observability_pipeline');

        $this->assertSame(['atlas_ai_chat' => 1], $response->json('kernel_pipeline.surface_counts'));
        $this->assertSame(['atlas.kernel.pipeline' => 1], $response->json('kernel_pipeline.emitter_stage_counts'));
        $this->assertSame(['programming.dev' => 1], $response->json('kernel_pipeline.flow_counts'));
        $this->assertSame(['declared_dev_plan' => 1], $response->json('kernel_pipeline.input_mode_counts'));
        $this->assertSame(['provider_execution_allowed_must_be_false' => 1], $response->json('kernel_pipeline.violation_counts'));
    }

    public function test_observability_payload_includes_self_improvement_schedule(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        AtlasLedgerEvent::query()->create([
            'event_id' => '01HOBSSCHEDULE0000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_observability',
            'operator_id' => 'operator_observability',
            'envelope_id' => 'self_improvement_run:observability',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'self_improvement_run:observability',
            'causation_id' => null,
            'event_type' => LedgerEventType::SelfImprovementScheduleObserved->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.nightly_review',
                'schedule_health' => [
                    'health_status' => 'healthy',
                    'issues' => [],
                    'enabled' => true,
                    'schedulable' => true,
                    'scheduler_registration' => [
                        'status' => 'registered',
                        'registered_command_count' => 4,
                        'skipped_reason' => null,
                    ],
                    'flow_count' => 4,
                    'cadence_counts' => ['daily' => 3, 'weekly' => 1],
                    'invalid_flow_count' => 0,
                    'defaulted' => false,
                    'emit' => false,
                    'plan_hash' => 'observability-plan-hash',
                    'plan_hash_algorithm' => 'sha256',
                    'time' => '02:00',
                    'timezone' => config('app.timezone'),
                    'next_run_at' => '2026-05-05T05:00:00.000000Z',
                ],
            ],
            'payload_hash' => hash('sha256', '01HOBSSCHEDULE0000000000001'),
            'occurred_at' => now(),
        ]);

        $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('self_improvement_schedule.enabled', true)
            ->assertJsonPath('self_improvement_schedule.schedulable', true)
            ->assertJsonPath('self_improvement_schedule.scheduler_registration.status', 'registered')
            ->assertJsonPath('self_improvement_schedule.scheduler_registration.registered_command_count', 4)
            ->assertJsonPath('self_improvement_schedule.cadence_counts.daily', 3)
            ->assertJsonPath('self_improvement_schedule.cadence_counts.weekly', 1)
            ->assertJsonPath('self_improvement_schedule.plan_hash_algorithm', 'sha256')
            ->assertJsonPath('self_improvement_schedule.time', '02:00')
            ->assertJsonPath('self_improvement_schedule.timezone', config('app.timezone'))
            ->assertJsonStructure(['self_improvement_schedule' => ['next_run_at']])
            ->assertJsonPath('self_improvement_schedule.count', 4)
            ->assertJsonPath('self_improvement_schedule.configured_flows.0', 'nightly_review')
            ->assertJsonPath('self_improvement_schedule.configured_flows.1', 'weekly_architecture_audit')
            ->assertJsonPath('self_improvement_schedule.configured_flows.2', 'repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.configured_flows.3', 'kernel_pipeline_review')
            ->assertJsonPath('self_improvement_schedule.invalid_flows', [])
            ->assertJsonPath('self_improvement_schedule.defaulted', false)
            ->assertJsonPath('self_improvement_schedule.health.status', 'healthy')
            ->assertJsonPath('self_improvement_schedule.flows.0', 'nightly_review')
            ->assertJsonPath('self_improvement_schedule.flows.1', 'weekly_architecture_audit')
            ->assertJsonPath('self_improvement_schedule.flows.2', 'repair_loop_review')
            ->assertJsonPath('self_improvement_schedule.flows.3', 'kernel_pipeline_review')
            ->assertJsonPath('self_improvement_schedule.commands.1.command', 'atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json')
            ->assertJsonPath('self_improvement_schedule.commands.1.cadence', 'weekly')
            ->assertJsonPath('self_improvement_schedule.commands.1.week_day', 1)
            ->assertJsonPath('self_improvement_schedule.commands.2.command', 'atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json')
            ->assertJsonPath('self_improvement_schedule.commands.2.cadence', 'daily')
            ->assertJsonPath('self_improvement_schedule.commands.3.command', 'atlas:ai:self-improve --flow=kernel_pipeline_review --hours=24 --limit=5 --json')
            ->assertJsonPath('self_improvement_schedule.commands.3.cadence', 'daily')
            ->assertJsonPath('self_improvement_schedule_replay.available', true)
            ->assertJsonPath('self_improvement_schedule_replay.schedule_observation_count', 1)
            ->assertJsonPath('self_improvement_schedule_replay.health_status_counts.healthy', 1)
            ->assertJsonPath('self_improvement_schedule_replay.latest_plan_hash', 'observability-plan-hash')
            ->assertJsonPath('self_improvement_schedule_replay.health.status', 'ok')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.status', 'ok')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.severity', 'none')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.recommended_action', 'none')
            ->assertJsonPath('domain_catalog.status', 'ok')
            ->assertJsonPath('domain_catalog.validation.valid', true)
            ->assertJsonPath('domain_catalog.summary.executable_incomplete_domains', 0)
            ->assertJsonStructure(['domain_catalog' => ['summary' => ['ready_domains', 'scaffold_domains', 'onboarding_status_counts']]])
            ->assertJsonPath('self_improvement_schedule.emit', false);
    }

    public function test_observability_payload_includes_architecture_validation_summary(): void
    {
        $response = $this->getJson('/ai/observability?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_validation.status', 'ok')
            ->assertJsonPath('architecture_validation.schema_version', 1)
            ->assertJsonPath('architecture_validation.kernel.valid', true)
            ->assertJsonPath('architecture_validation.kernel.static_scan.valid', true)
            ->assertJsonPath('architecture_validation.kernel.static_scan.summary.failed_count', 0)
            ->assertJsonPath('architecture_validation.kernel.static_scan.summary.violation_count', 0)
            ->assertJsonPath('architecture_validation.capabilities.valid', true)
            ->assertJsonPath('architecture_validation.domains.valid', true)
            ->assertJsonPath('architecture_validation.orchestrators.valid', true);

        $this->assertGreaterThanOrEqual(30, $response->json('architecture_validation.kernel.static_scan.summary.total_count'));
        $this->assertSame(
            $response->json('architecture_validation.kernel.static_scan.summary.total_count'),
            $response->json('architecture_validation.kernel.static_scan.summary.passed_count')
        );
        $this->assertContains('ap37_architecture_validation_surface', $response->json('architecture_validation.kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap38_architecture_validation_observability', $response->json('architecture_validation.kernel.static_scan.summary.valid_keys'));
        $this->assertSame([], $response->json('architecture_validation.kernel.static_scan.summary.failed_keys'));
        $this->assertGreaterThanOrEqual(1, $response->json('architecture_validation.onboarding.ready_domains'));
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
