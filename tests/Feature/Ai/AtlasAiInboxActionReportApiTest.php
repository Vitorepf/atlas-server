<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiInboxActionReportApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_inbox_action_report_api_returns_window_summary(): void
    {
        $this->recordInboxAction('01HINBOXACTIONAPI0000001', 'api-inbox-action-a', 'review_patch', 'operator_cli', 'review_observability_patch', []);
        $this->recordInboxAction('01HINBOXACTIONAPI0000002', 'api-inbox-action-b', 'dismiss', 'operator_app', 'dismiss_low_signal_proposal', ['patch.diff']);

        $response = $this->getJson('/ai/inbox-actions/report?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('inbox_actions.available', true)
            ->assertJsonPath('inbox_actions.inbox_action_count', 2)
            ->assertJsonPath('inbox_actions.envelope_count', 2)
            ->assertJsonPath('inbox_actions.reviewed_patch_count', 1)
            ->assertJsonPath('inbox_actions.with_diff_refs_count', 1)
            ->assertJsonPath('inbox_actions.review_signal.status', 'ok')
            ->assertJsonPath('inbox_actions.review_signal.recommended_action', 'none');

        $this->assertSame(['review_patch' => 1, 'dismiss' => 1], $response->json('inbox_actions.action_counts'));
        $this->assertSame(['operator_cli' => 1, 'operator_app' => 1], $response->json('inbox_actions.actor_type_counts'));
    }

    public function test_inbox_action_report_api_filters_window_summary(): void
    {
        $this->recordInboxAction('01HINBOXACTIONAPIFILTER1', 'api-inbox-filter-a', 'review_patch', 'operator_cli', 'review_observability_patch', []);
        $this->recordInboxAction('01HINBOXACTIONAPIFILTER2', 'api-inbox-filter-b', 'dismiss', 'operator_app', 'dismiss_low_signal_proposal', ['patch.diff']);

        $response = $this->getJson('/ai/inbox-actions/report?hours=24&action=review_patch&actor_type=operator_cli&recommended_action=review_observability_patch', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.action', 'review_patch')
            ->assertJsonPath('filters.actor_type', 'operator_cli')
            ->assertJsonPath('filters.recommended_action', 'review_observability_patch')
            ->assertJsonPath('inbox_actions.inbox_action_count', 1)
            ->assertJsonPath('inbox_actions.envelope_count', 1)
            ->assertJsonPath('inbox_actions.review_signal.status', 'warning')
            ->assertJsonPath('inbox_actions.review_signal.severity', 'medium')
            ->assertJsonPath('inbox_actions.review_signal.recommended_action', 'open_reviewable_inbox_action_evidence_proposal');

        $this->assertSame('api-inbox-filter-a', $response->json('inbox_actions.recent_events.0.inbox_item_id'));
    }

    public function test_inbox_action_report_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/inbox-actions/report')
            ->assertUnauthorized();
    }

    public function test_inbox_action_report_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/inbox-actions/report', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('inbox_actions.available', false)
            ->assertJsonPath('inbox_actions.review_signal.status', 'unknown')
            ->assertJsonPath('inbox_actions.review_signal.recommended_action', 'wait_for_inbox_action_evidence');
    }

    /**
     * @param  array<int,string>  $diffRefs
     */
    private function recordInboxAction(string $eventId, string $inboxItemId, string $action, string $actorType, string $recommendedAction, array $diffRefs): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_inbox_action_report',
            'operator_id' => $actorType,
            'envelope_id' => 'inbox_item:'.$inboxItemId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $inboxItemId,
            'causation_id' => null,
            'event_type' => LedgerEventType::InboxActionRecorded->value,
            'emitter_stage' => 'atlas.inbox',
            'emitter_version' => 'atlas.inbox_action.v1',
            'payload' => [
                'schema_version' => 'atlas.inbox_action.v1',
                'action' => $action,
                'inbox_item' => [
                    'id' => $inboxItemId,
                    'type' => 'proposal',
                    'category' => 'architecture',
                    'severity' => 'medium',
                    'status' => 'read',
                ],
                'actor' => [
                    'type' => $actorType,
                    'id' => null,
                ],
                'result' => [
                    'payload' => [
                        'action' => $action,
                        'diff_refs' => $diffRefs,
                    ],
                ],
                'recommended_action' => $recommendedAction,
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => $recommendedAction,
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now()->subHour(),
        ]);
    }
}
