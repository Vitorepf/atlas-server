<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InboxLedgerProjectionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        $this->createProjectionTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_inbox_action_runs_ledger_projection_and_records_reviewable_evidence(): void
    {
        $this->recordLedgerEvent(
            eventId: '01HINBOXPROJECTIONPROVIDER001',
            envelopeId: 'env_inbox_projection',
            eventType: LedgerEventType::ProviderReturned,
            payload: [
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'operator_input' => 'Repair ledger projection drift.',
            ],
            emitterStage: 'provider_driver',
            traceId: '11111111-1111-4111-8111-111111111111',
        );
        $this->recordLedgerEvent(
            eventId: '01HINBOXPROJECTIONTOOL0001',
            envelopeId: 'env_inbox_projection',
            eventType: LedgerEventType::ToolReturned,
            payload: [
                'tool_slug' => 'phpunit',
                'surface' => 'atlas_dev',
                'run_context_id' => 'env_inbox_projection',
                'result' => ['exit_code' => 0],
            ],
            emitterStage: 'super_tool_runtime',
        );
        $item = $this->ledgerProjectionInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'run_ledger_projection',
            [],
            'test-run-ledger-projection-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue((bool) data_get($result, 'result.applied'));
        $this->assertSame('atlas.ledger_projection_worker.v1', data_get($result, 'result.ledger_projection.schema_version'));
        $this->assertSame(2, data_get($result, 'result.ledger_projection.event_count'));
        $this->assertSame(3, data_get($result, 'result.ledger_projection.projected_count'));
        $this->assertSame('critical', data_get($result, 'result.source_health_status'));
        $this->assertSame('atlas:ai:ledger-project --hours=24 --limit=500 --json', data_get($result, 'result.command'));

        $item->refresh();
        $this->assertSame('resolved', $item->status);
        $this->assertSame('run_ledger_projection', data_get($item->response, 'action'));
        $this->assertSame('atlas.inbox_action.ledger_projection.v1', data_get($item->payload, 'ledger_projection_action.schema_version'));
        $this->assertSame(3, data_get($item->payload, 'ledger_projection_action.projected_count'));
        $this->assertNotNull($item->resolved_at);

        $this->assertSame(1, AiTrace::query()->count());
        $this->assertSame(1, AtlasToolRun::query()->count());
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::InboxActionRecorded->value,
            'envelope_id' => 'inbox_item:'.$item->id,
            'emitter_stage' => 'atlas.inbox',
        ]);

        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->firstOrFail();
        $this->assertSame('run_ledger_projection', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame('atlas.inbox_action.ledger_projection.v1', data_get($ledgerEvent->payload, 'result.ledger_projection_action.schema_version'));
        $this->assertSame('run_atlas_ai_ledger_project_or_review_projection_tables', data_get($ledgerEvent->payload, 'recommended_action'));
    }

    public function test_inbox_action_can_preview_ledger_projection_without_resolving_item(): void
    {
        $this->recordLedgerEvent(
            eventId: '01HINBOXPROJECTIONDRYRUN001',
            envelopeId: 'env_inbox_projection_dry_run',
            eventType: LedgerEventType::ToolReturned,
            payload: [
                'tool_slug' => 'phpstan',
                'surface' => 'atlas_dev',
                'run_context_id' => 'env_inbox_projection_dry_run',
            ],
            emitterStage: 'super_tool_runtime',
        );
        $item = $this->ledgerProjectionInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'run_ledger_projection',
            ['dry_run' => true],
            'test-run-ledger-projection-dry-run-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse((bool) data_get($result, 'result.applied'));
        $this->assertTrue((bool) data_get($result, 'result.dry_run'));
        $this->assertSame('atlas:ai:ledger-project --hours=24 --limit=500 --dry-run --json', data_get($result, 'result.command'));

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame(0, AtlasToolRun::query()->count());
        $this->assertSame('would_project', data_get($result, 'result.ledger_projection.projection_results.0.status'));
    }

    private function ledgerProjectionInboxItem(): AiInboxItem
    {
        return AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Ledger projection precisa de backfill',
            'summary' => 'Projection health encontrou drift entre o Evidence Ledger e os read models.',
            'body' => 'Execute a projection assistida para recuperar ai_traces, atlas_engineering_runs e atlas_tool_runs.',
            'source_type' => 'ledger_projection_health',
            'source_id' => (string) Str::uuid(),
            'initiator' => 'system',
            'dedupe_key' => 'ledger-projection-health:'.(string) Str::uuid(),
            'available_actions' => [
                ['id' => 'run_ledger_projection', 'label' => 'Run projection', 'style' => 'primary'],
                ['id' => 'review_patch', 'label' => 'Review evidence', 'style' => 'secondary'],
            ],
            'payload' => [
                'ledger_projection_health' => [
                    'schema_version' => 'atlas.ledger_projection_health.v1',
                    'status' => 'critical',
                    'scheduler' => [
                        'hours' => 24,
                        'limit' => 500,
                    ],
                ],
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => 'warning',
                        'reason' => 'ledger_projection_health_attention',
                        'recommended_action' => 'run_atlas_ai_ledger_project_or_review_projection_tables',
                    ],
                    'source_refs' => [
                        ['type' => 'ledger_projection_health', 'id' => 'atlas.ledger_projection_health.v1'],
                    ],
                ],
            ],
            'push_policy' => [],
            'priority_score' => 85,
            'confidence_score' => 0.92,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordLedgerEvent(
        string $eventId,
        string $envelopeId,
        LedgerEventType $eventType,
        array $payload,
        string $emitterStage,
        ?string $traceId = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_inbox_projection_test',
            'operator_id' => 'operator_inbox_projection_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => 'receipt_'.$envelopeId,
            'trace_id' => $traceId,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => $eventType->value,
            'emitter_stage' => $emitterStage,
            'emitter_version' => 'inbox-projection-test-v1',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'occurred_at' => now(),
        ]);
    }

    private function createProjectionTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->string('status');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('trace_id')->nullable();
            $table->string('workspace_path_hash');
            $table->string('workspace_label');
            $table->json('provider_strategy_json')->nullable();
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tool_slug');
            $table->string('surface');
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->boolean('required')->default(false);
            $table->string('failure_policy');
            $table->string('policy_decision');
            $table->json('summary_json')->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestampsTz();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
