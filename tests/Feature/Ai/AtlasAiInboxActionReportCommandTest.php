<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiInboxActionReportCommandTest extends TestCase
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

    public function test_command_summarizes_inbox_action_window_as_json(): void
    {
        $this->recordInboxAction('01HINBOXACTIONCMD0000001', 'cmd-inbox-action-a', 'review_patch', 'operator_cli', 'review_observability_patch', []);
        $this->recordInboxAction('01HINBOXACTIONCMD0000002', 'cmd-inbox-action-b', 'dismiss', 'operator_cli', 'dismiss_low_signal_proposal', ['patch.diff']);

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame(2, data_get($payload, 'inbox_actions.inbox_action_count'));
        $this->assertSame(2, data_get($payload, 'inbox_actions.envelope_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.reviewed_patch_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.with_diff_refs_count'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('none', data_get($payload, 'inbox_actions.review_signal.recommended_action'));
        $this->assertSame(['review_patch' => 1, 'dismiss' => 1], data_get($payload, 'inbox_actions.action_counts'));
    }

    public function test_command_filters_inbox_action_report_as_json(): void
    {
        $this->recordInboxAction('01HINBOXACTIONCMDFILTER1', 'cmd-inbox-filter-a', 'review_patch', 'operator_cli', 'review_observability_patch', []);
        $this->recordInboxAction('01HINBOXACTIONCMDFILTER2', 'cmd-inbox-filter-b', 'dismiss', 'operator_app', 'dismiss_low_signal_proposal', ['patch.diff']);

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_patch',
            '--actor-type' => 'operator_cli',
            '--recommended-action' => 'review_observability_patch',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'action' => 'review_patch',
            'actor_type' => 'operator_cli',
            'recommended_action' => 'review_observability_patch',
        ], $payload['filters']);
        $this->assertSame($payload['filters'], data_get($payload, 'inbox_actions.filters'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.inbox_action_count'));
        $this->assertSame('warning', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('open_reviewable_inbox_action_evidence_proposal', data_get($payload, 'inbox_actions.review_signal.recommended_action'));
        $this->assertSame('cmd-inbox-filter-a', data_get($payload, 'inbox_actions.recent_events.0.inbox_item_id'));
    }

    public function test_command_human_output_includes_inbox_action_review_signal(): void
    {
        $this->recordInboxAction('01HINBOXACTIONCMDHUMAN01', 'cmd-inbox-human', 'review_patch', 'operator_cli', 'review_observability_patch', []);

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('Review severity', $output);
        $this->assertStringContainsString('Recommended action', $output);
        $this->assertStringContainsString('open_reviewable_inbox_action_evidence_proposal', $output);
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'inbox_actions.available'));
        $this->assertSame('unknown', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('wait_for_inbox_action_evidence', data_get($payload, 'inbox_actions.review_signal.recommended_action'));
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
