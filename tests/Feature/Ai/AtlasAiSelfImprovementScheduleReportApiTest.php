<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiSelfImprovementScheduleReportApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');

        parent::tearDown();
    }

    public function test_schedule_report_api_returns_window_summary(): void
    {
        $this->recordScheduleObservation('01HSCHEDREPORTAPI00000001', 'self_improvement_run:api_ok', 'healthy', 'registered', []);
        $this->recordScheduleObservation('01HSCHEDREPORTAPI00000002', 'self_improvement_run:api_warning', 'warning', 'registered', ['invalid_self_improvement_flows_configured'], 1);
        $this->recordCompletion(
            '01HSCHEDREPORTAPIDONE001',
            'self_improvement_run:api_warning',
            ['00000000-0000-0000-0000-000000000654'],
        );
        $this->recordInboxItem('00000000-0000-0000-0000-000000000654', 'API schedule warning proposal');

        $this->getJson('/ai/self-improvement/schedule/report?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('self_improvement_schedule_replay.available', true)
            ->assertJsonPath('self_improvement_schedule_replay.schedule_observation_count', 2)
            ->assertJsonPath('self_improvement_schedule_replay.envelope_count', 2)
            ->assertJsonPath('self_improvement_schedule_replay.health_status_counts.healthy', 1)
            ->assertJsonPath('self_improvement_schedule_replay.health_status_counts.warning', 1)
            ->assertJsonPath('self_improvement_schedule_replay.warning_count', 1)
            ->assertJsonPath('self_improvement_schedule_replay.review_required', true)
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.status', 'warning')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.severity', 'medium')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.recommended_action', 'open_reviewable_self_improvement_schedule_proposal')
            ->assertJsonPath('self_improvement_schedule_replay.completed_count', 1)
            ->assertJsonPath('self_improvement_schedule_replay.emitted_count', 1)
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_item_ids.0', '00000000-0000-0000-0000-000000000654')
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_item_hydration_available', true)
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_item_missing_ids', [])
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_items.0.title', 'API schedule warning proposal')
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_items.0.status', 'unread')
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_items.0.review_signal.recommended_action', 'review_schedule_repair')
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.envelope_id', 'self_improvement_run:api_warning')
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.completed', true)
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.emitted_count', 1)
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.emitted_inbox_item_ids.0', '00000000-0000-0000-0000-000000000654')
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.emitted_inbox_items.0.title', 'API schedule warning proposal');
    }

    public function test_schedule_report_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/self-improvement/schedule/report')
            ->assertUnauthorized();
    }

    public function test_schedule_report_api_exposes_missing_inbox_refs(): void
    {
        $this->recordScheduleObservation('01HSCHEDREPORTAPIMISS001', 'self_improvement_run:api_missing_ref', 'warning', 'registered', ['missing_inbox_ref'], 1);
        $this->recordCompletion(
            '01HSCHEDREPORTAPIMISS002',
            'self_improvement_run:api_missing_ref',
            ['00000000-0000-0000-0000-000000000998'],
        );

        $this->getJson('/ai/self-improvement/schedule/report?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_item_hydration_available', true)
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_item_missing_ids.0', '00000000-0000-0000-0000-000000000998')
            ->assertJsonPath('self_improvement_schedule_replay.emitted_inbox_items', [])
            ->assertJsonPath('self_improvement_schedule_replay.recent_events.0.emitted_inbox_item_missing_ids.0', '00000000-0000-0000-0000-000000000998');
    }

    public function test_schedule_report_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $response = $this->getJson('/ai/self-improvement/schedule/report', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('self_improvement_schedule_replay.available', false)
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.status', 'unknown')
            ->assertJsonPath('self_improvement_schedule_replay.review_signal.recommended_action', 'wait_for_next_self_improvement_cycle')
            ->assertJsonPath('self_improvement_schedule_replay.recent_events', []);

        $this->assertArrayNotHasKey('events', $response->json('self_improvement_schedule_replay'));
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
                        'registered_command_count' => $schedulerStatus === 'registered' ? 5 : 0,
                        'skipped_reason' => $schedulerStatus === 'skipped' ? ($issues[0] ?? 'not_schedulable') : null,
                    ],
                    'flow_count' => 5,
                    'cadence_counts' => ['daily' => 4, 'weekly' => 1],
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

    /**
     * @param  array<int,string>  $emittedInboxItemIds
     */
    private function recordCompletion(string $eventId, string $envelopeId, array $emittedInboxItemIds): void
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
            'event_type' => LedgerEventType::OperationCompleted->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'finding_count' => count($emittedInboxItemIds),
                'emitted_count' => count($emittedInboxItemIds),
                'emitted_inbox_item_ids' => $emittedInboxItemIds,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }

    private function recordInboxItem(string $id, string $title): void
    {
        AiInboxItem::unguarded(fn (): AiInboxItem => AiInboxItem::query()->create([
            'id' => $id,
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => $title,
            'summary' => 'Self-Improvement schedule replay emitted this proposal.',
            'source_type' => 'atlas_self_improvement',
            'initiator' => 'system',
            'payload' => [
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'recommended_action' => 'review_schedule_repair',
                    ],
                ],
            ],
            'deep_link' => 'atlas://inbox/'.$id,
        ]));
    }
}
