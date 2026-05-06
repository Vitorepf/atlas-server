<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiSelfImprovementScheduleReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_summarizes_self_improvement_schedule_replay_as_json(): void
    {
        $this->recordScheduleObservation('01HSCHEDREPORTCMD00000001', 'self_improvement_run:cmd_ok', 'healthy', 'registered', []);
        $this->recordScheduleObservation('01HSCHEDREPORTCMD00000002', 'self_improvement_run:cmd_warning', 'warning', 'registered', ['invalid_self_improvement_flows_configured'], 1);

        $exit = Artisan::call('atlas:ai:self-improvement-schedule-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame(2, data_get($payload, 'self_improvement_schedule_replay.schedule_observation_count'));
        $this->assertSame(['healthy' => 1, 'warning' => 1], data_get($payload, 'self_improvement_schedule_replay.health_status_counts'));
        $this->assertSame(1, data_get($payload, 'self_improvement_schedule_replay.warning_count'));
        $this->assertTrue((bool) data_get($payload, 'self_improvement_schedule_replay.review_required'));
        $this->assertSame('warning', data_get($payload, 'self_improvement_schedule_replay.review_signal.status'));
        $this->assertSame('medium', data_get($payload, 'self_improvement_schedule_replay.review_signal.severity'));
        $this->assertSame('open_reviewable_self_improvement_schedule_proposal', data_get($payload, 'self_improvement_schedule_replay.review_signal.recommended_action'));
        $this->assertSame('self_improvement_run:cmd_warning', data_get($payload, 'self_improvement_schedule_replay.recent_events.0.envelope_id'));
    }

    public function test_command_human_output_includes_schedule_replay_review_signal(): void
    {
        $this->recordScheduleObservation('01HSCHEDREPORTCMD00000003', 'self_improvement_run:cmd_human_warning', 'warning', 'registered', ['invalid_self_improvement_flows_configured'], 1);

        $exit = Artisan::call('atlas:ai:self-improvement-schedule-report', [
            '--hours' => 24,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('Review severity', $output);
        $this->assertStringContainsString('Recommended action', $output);
        $this->assertStringContainsString('open_reviewable_self_improvement_schedule_proposal', $output);
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:self-improvement-schedule-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'self_improvement_schedule_replay.available'));
        $this->assertSame('unknown', data_get($payload, 'self_improvement_schedule_replay.review_signal.status'));
        $this->assertSame('wait_for_next_self_improvement_cycle', data_get($payload, 'self_improvement_schedule_replay.review_signal.recommended_action'));
        $this->assertSame([], data_get($payload, 'self_improvement_schedule_replay.recent_events'));
        $this->assertArrayNotHasKey('events', data_get($payload, 'self_improvement_schedule_replay'));
    }

    /**
     * @param  array<int,string>  $issues
     */
    private function recordScheduleObservation(string $eventId, string $envelopeId, string $healthStatus, string $schedulerStatus, array $issues, int $invalidFlowCount = 0): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_schedule_report',
            'operator_id' => 'operator_schedule_report',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::SelfImprovementScheduleObserved->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'schedule_health' => [
                    'health_status' => $healthStatus,
                    'issues' => $issues,
                    'enabled' => true,
                    'schedulable' => $schedulerStatus === 'registered',
                    'scheduler_registration' => [
                        'status' => $schedulerStatus,
                        'registered_command_count' => $schedulerStatus === 'registered' ? 4 : 0,
                        'skipped_reason' => $schedulerStatus === 'skipped' ? ($issues[0] ?? 'not_schedulable') : null,
                    ],
                    'flow_count' => 4,
                    'cadence_counts' => ['daily' => 3, 'weekly' => 1],
                    'invalid_flow_count' => $invalidFlowCount,
                    'defaulted' => false,
                    'emit' => false,
                    'plan_hash' => 'schedule-report-plan-hash',
                    'plan_hash_algorithm' => 'sha256',
                    'time' => '02:00',
                    'timezone' => 'America/Sao_Paulo',
                    'next_run_at' => '2026-05-05T05:00:00.000000Z',
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
