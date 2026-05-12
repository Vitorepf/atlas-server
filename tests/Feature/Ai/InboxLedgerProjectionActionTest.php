<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AiProviderCostRate;
use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasStrategyRivalsReview;
use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyCaseRegistrar;
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
        (require database_path('migrations/2026_05_06_120000_create_atlas_strategy_rivals_tables.php'))->up();
        $this->createProviderCostRateTable();
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

    public function test_inbox_action_records_rivals_review_with_human_scores_and_ledger_evidence(): void
    {
        $registration = app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Escolher direcao do Atlas',
            'baseline_choice' => 'Decisao direta',
            'atlas_assisted_choice' => 'Revisao estrategica plan-only',
        ]);
        $review = AtlasStrategyRivalsReview::query()
            ->where('case_id', $registration['case_id'])
            ->where('horizon_days', 30)
            ->firstOrFail();
        $item = $this->rivalsDueReviewInboxItem((string) $registration['case_id'], (string) $review->id);

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'record_rivals_review',
            [
                'regret_score' => 12,
                'alignment_score' => 91,
                'agency_score' => 86,
                'outcome_summary' => 'A revisita confirmou que o Atlas ajudou sem reduzir agencia.',
            ],
            'test-record-rivals-review-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('atlas.rivals_strategy.review_recording.v1', data_get($result, 'result.recorded_review.schema_version'));
        $this->assertSame(12, data_get($result, 'result.recorded_review.scores.regret'));
        $this->assertSame(91, data_get($result, 'result.recorded_review.scores.alignment'));
        $this->assertSame(86, data_get($result, 'result.recorded_review.scores.agency'));
        $this->assertSame(0, data_get($result, 'result.remaining_due_review_count'));

        $item->refresh();
        $this->assertSame('resolved', $item->status);
        $this->assertSame('record_rivals_review', data_get($item->response, 'action'));
        $this->assertSame('atlas.inbox_action.rivals_review.v1', data_get($item->payload, 'rivals_review_action.schema_version'));
        $this->assertSame(0, data_get($item->payload, 'rivals_strategy.due_review_count'));
        $this->assertNotNull($item->resolved_at);

        $this->assertDatabaseHas('atlas_strategy_rivals_reviews', [
            'id' => $review->id,
            'status' => 'reviewed',
            'regret_score' => 12,
            'alignment_score' => 91,
            'agency_score' => 86,
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::InboxActionRecorded->value,
            'envelope_id' => 'inbox_item:'.$item->id,
            'emitter_stage' => 'atlas.inbox',
        ]);

        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->where('envelope_id', 'inbox_item:'.$item->id)
            ->firstOrFail();
        $this->assertSame('record_rivals_review', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame('atlas.inbox_action.rivals_review.v1', data_get($ledgerEvent->payload, 'result.rivals_review_action.schema_version'));
        $this->assertSame('record_due_rivals_strategy_reviews', data_get($ledgerEvent->payload, 'recommended_action'));
    }

    public function test_inbox_action_reviews_memory_retrieval_regression_without_runtime_or_raw_context(): void
    {
        $item = $this->retrievalRegressionInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'review_retrieval_regression',
            [
                'decision' => 'accepted_regression',
                'operator_note' => 'Abrir follow-up para revisar ranking e corpus.',
            ],
            'test-review-retrieval-regression-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue((bool) data_get($result, 'result.reviewed'));
        $this->assertTrue((bool) data_get($result, 'result.no_external_action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_regression_review.v1', data_get($result, 'result.retrieval_regression_review_action.schema_version'));
        $this->assertSame('accepted_regression', data_get($result, 'result.retrieval_regression_review_action.decision'));
        $this->assertTrue((bool) data_get($result, 'result.retrieval_regression_review_action.no_runtime_execution'));
        $this->assertSame('retrieval-report-hash', data_get($result, 'result.retrieval_regression_review_action.report_hash'));

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame('review_retrieval_regression', data_get($item->response, 'action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_regression_review.v1', data_get($item->payload, 'retrieval_regression_review_action.schema_version'));
        $this->assertSame('accepted_regression', data_get($item->payload, 'retrieval_regression_review_action.decision'));
        $this->assertTrue((bool) data_get($item->payload, 'retrieval_regression_review_action.no_external_action'));
        $this->assertStringNotContainsString('Exact local capture evidence', json_encode($item->payload, JSON_THROW_ON_ERROR));

        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->where('envelope_id', 'inbox_item:'.$item->id)
            ->firstOrFail();
        $this->assertSame('review_retrieval_regression', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_regression_review.v1', data_get($ledgerEvent->payload, 'result.retrieval_regression_review_action.schema_version'));
        $this->assertSame('open_memory_retrieval_regression_review', data_get($ledgerEvent->payload, 'recommended_action'));
        $this->assertStringNotContainsString('Exact local capture evidence', $ledgerEvent->toJson());
    }

    public function test_inbox_action_reviews_memory_retrieval_shadow_scope_without_runtime_or_policy_patch(): void
    {
        $item = $this->retrievalShadowScopeInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'review_retrieval_shadow_scope',
            [
                'decision' => 'needs_more_evidence',
                'operator_note' => 'Exigir contrato de casos antes de qualquer shadow run.',
            ],
            'test-review-retrieval-shadow-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue((bool) data_get($result, 'result.reviewed'));
        $this->assertTrue((bool) data_get($result, 'result.no_external_action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_shadow_scope_review.v1', data_get($result, 'result.retrieval_shadow_scope_review_action.schema_version'));
        $this->assertSame('needs_more_evidence', data_get($result, 'result.retrieval_shadow_scope_review_action.decision'));
        $this->assertTrue((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.no_runtime_execution'));
        $this->assertTrue((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.no_policy_patch'));
        $this->assertTrue((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.no_provider_call'));
        $this->assertSame('shadow-plan-hash', data_get($result, 'result.retrieval_shadow_scope_review_action.plan_hash'));
        $this->assertSame('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', data_get($result, 'result.retrieval_shadow_scope_review_action.review_ap'));
        $this->assertSame('atlas.memory_retrieval_shadow_scope_decision_receipt.v1', data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.schema_version'));
        $this->assertTrue((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.dry_run'));
        $this->assertFalse((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.shadow_execution_allowed_now'));
        $this->assertFalse((bool) data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.scope_approved'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt_hash'));
        $this->assertContains('shadow_execution_allowed_now', data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.receipt_hash_fields'));
        $this->assertNotContains('issued_at', data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt.receipt_hash_fields'));

        $sameDecisionItem = $this->retrievalShadowScopeInboxItem('same-decision');
        $sameDecisionResult = app(InboxActionRegistry::class)->handle(
            $sameDecisionItem,
            'review_retrieval_shadow_scope',
            [
                'decision' => 'needs_more_evidence',
                'operator_note' => 'Exigir contrato de casos antes de qualquer shadow run.',
            ],
            'test-review-retrieval-shadow-same-decision-'.$sameDecisionItem->id,
        );
        $this->assertSame(
            data_get($result, 'result.retrieval_shadow_scope_review_action.decision_receipt_hash'),
            data_get($sameDecisionResult, 'result.retrieval_shadow_scope_review_action.decision_receipt_hash'),
        );

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame('review_retrieval_shadow_scope', data_get($item->response, 'action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_shadow_scope_review.v1', data_get($item->payload, 'retrieval_shadow_scope_review_action.schema_version'));
        $this->assertStringNotContainsString('Exact local capture evidence', json_encode($item->payload, JSON_THROW_ON_ERROR));

        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->where('envelope_id', 'inbox_item:'.$item->id)
            ->firstOrFail();
        $this->assertSame('review_retrieval_shadow_scope', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame('atlas.inbox_action.memory_retrieval_shadow_scope_review.v1', data_get($ledgerEvent->payload, 'result.retrieval_shadow_scope_review_action.schema_version'));
        $this->assertSame('atlas.memory_retrieval_shadow_scope_decision_receipt.v1', data_get($ledgerEvent->payload, 'result.retrieval_shadow_scope_review_action.decision_receipt.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($ledgerEvent->payload, 'result.retrieval_shadow_scope_review_action.decision_receipt.receipt_hash'));
        $this->assertSame('review_retrieval_shadow_scope', data_get($ledgerEvent->payload, 'recommended_action'));
        $this->assertStringNotContainsString('Exact local capture evidence', $ledgerEvent->toJson());
    }

    public function test_inbox_action_configures_provider_cost_rates_with_human_supplied_rates_and_ledger_evidence(): void
    {
        $item = $this->providerCostRateInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'configure_provider_cost_rates',
            [
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
            ],
            'test-configure-provider-cost-rates-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue((bool) data_get($result, 'result.applied'));
        $this->assertSame('atlas.inbox_action.provider_cost_rates.v1', data_get($result, 'result.provider_cost_rate_action.schema_version'));
        $this->assertSame('codex_cli', data_get($result, 'result.upserted_rate.provider'));
        $this->assertSame('gpt-5.5', data_get($result, 'result.upserted_rate.model'));
        $this->assertSame(120, data_get($result, 'result.upserted_rate.input_microusd_per_1k'));
        $this->assertSame(480, data_get($result, 'result.upserted_rate.output_microusd_per_1k'));

        $item->refresh();
        $this->assertSame('resolved', $item->status);
        $this->assertSame('configure_provider_cost_rates', data_get($item->response, 'action'));
        $this->assertSame('atlas.inbox_action.provider_cost_rates.v1', data_get($item->payload, 'provider_cost_rate_action.schema_version'));
        $this->assertSame('codex_cli', data_get($item->payload, 'provider_cost_rate_action.provider'));
        $this->assertNotNull($item->resolved_at);

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_microusd_per_1k' => 120,
            'output_microusd_per_1k' => 480,
            'currency' => 'USD',
        ]);

        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->where('envelope_id', 'inbox_item:'.$item->id)
            ->firstOrFail();
        $this->assertSame('configure_provider_cost_rates', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame('atlas.inbox_action.provider_cost_rates.v1', data_get($ledgerEvent->payload, 'result.provider_cost_rate_action.schema_version'));
        $this->assertSame('configure_provider_cost_rates', data_get($ledgerEvent->payload, 'recommended_action'));
    }

    public function test_inbox_action_accepts_zero_provider_cost_rates_without_external_lookup(): void
    {
        $item = $this->providerCostRateInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'configure_provider_cost_rates',
            [
                'input_microusd_per_1k' => '0',
                'output_microusd_per_1k' => 0,
                'currency' => 'USD',
            ],
            'test-configure-provider-zero-cost-rates-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue((bool) data_get($result, 'result.applied'));
        $this->assertSame(0, data_get($result, 'result.provider_cost_rate_action.input_microusd_per_1k'));
        $this->assertSame(0, data_get($result, 'result.provider_cost_rate_action.output_microusd_per_1k'));
        $this->assertTrue((bool) data_get($result, 'result.provider_cost_rate_action.no_external_action'));

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_microusd_per_1k' => 0,
            'output_microusd_per_1k' => 0,
            'currency' => 'USD',
        ]);
    }

    public function test_inbox_action_rejects_negative_provider_cost_rates_as_preview_only(): void
    {
        $item = $this->providerCostRateInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'configure_provider_cost_rates',
            [
                'input_microusd_per_1k' => '-1',
                'output_microusd_per_1k' => -10,
                'currency' => 'USD',
            ],
            'test-reject-negative-provider-cost-rates-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse((bool) data_get($result, 'result.applied'));
        $this->assertNull(data_get($result, 'result.provider_cost_rate_action.input_microusd_per_1k'));
        $this->assertNull(data_get($result, 'result.provider_cost_rate_action.output_microusd_per_1k'));
        $this->assertTrue((bool) data_get($result, 'result.provider_cost_rate_action.no_external_action'));

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame(0, AiProviderCostRate::query()->count());
    }

    public function test_inbox_action_previews_provider_cost_rate_template_without_resolving_item(): void
    {
        $item = $this->providerCostRateInboxItem();

        $result = app(InboxActionRegistry::class)->handle(
            $item,
            'configure_provider_cost_rates',
            [],
            'test-preview-provider-cost-rates-'.$item->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse((bool) data_get($result, 'result.applied'));
        $this->assertSame('codex_cli', data_get($result, 'result.rate_template.provider'));
        $this->assertSame('gpt-5.5', data_get($result, 'result.rate_template.model'));
        $this->assertSame('<fill_current_input_microusd_per_1k>', data_get($result, 'result.rate_template.input_microusd_per_1k'));
        $this->assertNull(data_get($result, 'result.upserted_rate'));

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame(0, AiProviderCostRate::query()->count());
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

    private function rivalsDueReviewInboxItem(string $caseId, string $reviewId): AiInboxItem
    {
        return AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Registrar revisitas pendentes do Rivals Strategy',
            'summary' => 'Rivals Strategy possui revisita pendente nos proximos 30 dias.',
            'body' => 'Pontue arrependimento, alinhamento e preservacao de agencia antes de usar este caso para claims P4+.',
            'source_type' => 'atlas_initiative_run',
            'source_id' => (string) Str::uuid(),
            'initiator' => 'system',
            'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('due-reviews:1'),
            'available_actions' => [
                ['id' => 'record_rivals_review', 'label' => 'Registrar score', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'secondary'],
            ],
            'payload' => [
                'rivals_strategy' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'due_review_count' => 1,
                    'record_command_template' => 'atlas:ai:rivals-strategy record-review --review-id=<review-id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
                ],
                'due_reviews' => [
                    [
                        'review_id' => $reviewId,
                        'case_id' => $caseId,
                        'case_title' => 'Escolher direcao do Atlas',
                        'horizon_days' => 30,
                        'record_command' => 'atlas:ai:rivals-strategy record-review --review-id='.$reviewId.' --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
                    ],
                ],
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => 'warning',
                        'reason' => 'rivals_strategy_due_reviews',
                        'recommended_action' => 'record_due_rivals_strategy_reviews',
                    ],
                    'source_refs' => [
                        ['type' => 'rivals_strategy_due_review', 'id' => $reviewId],
                    ],
                ],
            ],
            'push_policy' => [],
            'priority_score' => 82,
            'confidence_score' => 0.88,
        ]);
    }

    private function providerCostRateInboxItem(): AiInboxItem
    {
        return AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Configurar rates de custo dos providers',
            'summary' => 'AP-99 encontrou custo desconhecido para provider/model em uso.',
            'body' => 'Informe os rates atuais para habilitar Dynamic Compute Market com custo honesto.',
            'source_type' => 'atlas_initiative_run',
            'source_id' => (string) Str::uuid(),
            'initiator' => 'system',
            'dedupe_key' => 'self-improvement:provider-cost-rates:'.(string) Str::uuid(),
            'available_actions' => [
                ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'primary'],
                ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
            ],
            'payload' => [
                'problem' => 'Sem cost rates, AP-99 nao pode comparar custo.',
                'proposal_contract' => [
                    'schema_version' => 'atlas.self_improvement.provider_cost_rates.v1',
                    'review_signal' => [
                        'status' => 'warning',
                        'reason' => 'provider_cost_rates_unknown',
                        'recommended_action' => 'configure_provider_cost_rates',
                    ],
                    'source_refs' => [
                        [
                            'type' => 'ledger_event',
                            'id' => 'provider_cost_event_1',
                            'provider_cli' => 'codex_cli',
                            'model' => 'gpt-5.5',
                            'cost_source' => 'missing_cost_rate',
                            'cost_mode' => 'unknown',
                        ],
                    ],
                ],
            ],
            'push_policy' => [],
            'priority_score' => 78,
            'confidence_score' => 0.82,
        ]);
    }

    private function retrievalRegressionInboxItem(): AiInboxItem
    {
        return AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'memory_quality',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Revisar regressao de retrieval Memory/Open Brain',
            'summary' => 'Rivals detectou regressao no benchmark longitudinal de retrieval.',
            'body' => 'Revise snapshots e metricas antes de alterar ranking, memoria ou runtime.',
            'source_type' => 'local_rag_benchmark',
            'source_id' => 'latest-snapshot-id',
            'initiator' => 'system',
            'dedupe_key' => 'memory-retrieval-rivals:latest-snapshot-id',
            'available_actions' => [
                ['id' => 'review_retrieval_regression', 'label' => 'Revisar regressao', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'secondary'],
            ],
            'payload' => [
                'retrieval_rivals' => [
                    'schema_version' => 'atlas.local_rag_benchmark.rivals_report.v1',
                    'status' => 'attention',
                    'report_hash' => 'retrieval-report-hash',
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                    'comparison' => [
                        'outcome' => 'regressed',
                        'score_delta' => -35,
                        'latest' => [
                            'id' => 'latest-snapshot-id',
                            'source_id_hash' => hash('sha256', 'latest-source-id'),
                            'score' => 60,
                            'metrics' => [
                                'precision_at_3' => 0.4,
                                'provider_safe_violation_count' => 1,
                            ],
                        ],
                        'previous' => [
                            'id' => 'previous-snapshot-id',
                            'source_id_hash' => hash('sha256', 'previous-source-id'),
                            'score' => 95,
                        ],
                    ],
                    'review_packet' => [
                        'schema_version' => 'atlas.memory_retrieval_rivals_review_packet.v1',
                        'status' => 'proposal_only',
                        'forbidden_actions' => [
                            'enable_python_runtime',
                            'persist_raw_query',
                            'persist_raw_context',
                        ],
                    ],
                ],
                'proposal_contract' => [
                    'schema_version' => 'atlas.memory_retrieval_rivals_inbox.v1',
                    'review_signal' => [
                        'status' => 'review_required',
                        'severity' => 'high',
                        'recommended_action' => 'open_memory_retrieval_regression_review',
                        'reasons' => [
                            'memory_quality_score_regressed',
                            'provider_safe_violation_count',
                        ],
                    ],
                    'source_refs' => [
                        ['type' => 'memory_quality_snapshot', 'id' => 'latest-snapshot-id'],
                        ['type' => 'memory_quality_snapshot', 'id' => 'previous-snapshot-id'],
                    ],
                ],
            ],
            'push_policy' => [],
            'priority_score' => 90,
            'confidence_score' => 0.86,
        ]);
    }

    private function retrievalShadowScopeInboxItem(?string $dedupeSuffix = null): AiInboxItem
    {
        return AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'memory_quality',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Revisar escopo shadow de retrieval Memory/Open Brain',
            'summary' => 'AP-693 precisa de revisao humana antes de qualquer shadow run.',
            'body' => 'Revise candidatos, gates e evidencias sem executar runtime, provider ou policy patch.',
            'source_type' => 'local_rag_benchmark',
            'source_id' => null,
            'initiator' => 'system',
            'dedupe_key' => 'memory-retrieval-rivals-shadow:shadow-plan-hash'.($dedupeSuffix ? ':'.$dedupeSuffix : ''),
            'available_actions' => [
                ['id' => 'review_retrieval_shadow_scope', 'label' => 'Revisar escopo', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'secondary'],
            ],
            'payload' => [
                'retrieval_rivals_shadow_plan' => [
                    'schema_version' => 'atlas.local_rag_benchmark.rivals_shadow_plan.v1',
                    'status' => 'blocked',
                    'mode' => 'proposal_only_no_runtime_execution',
                    'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                    'plan_hash' => 'shadow-plan-hash',
                    'raw_query_persisted' => false,
                    'raw_context_persisted' => false,
                    'review_packet' => [
                        'schema_version' => 'atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1',
                        'forbidden_actions' => [
                            'execute_python_graph_rag',
                            'persist_raw_query',
                            'persist_raw_context',
                        ],
                    ],
                ],
                'proposal_contract' => [
                    'schema_version' => 'atlas.memory_retrieval_rivals_shadow_inbox.v1',
                    'review_signal' => [
                        'status' => 'review_required',
                        'severity' => 'medium',
                        'recommended_action' => 'review_retrieval_shadow_scope',
                        'reasons' => [
                            'shadow_case_contract_missing',
                            'human_review_required',
                        ],
                    ],
                    'source_refs' => [
                        ['type' => 'ap', 'id' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md'],
                    ],
                ],
            ],
            'push_policy' => [],
            'priority_score' => 82,
            'confidence_score' => 0.84,
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

    private function createProviderCostRateTable(): void
    {
        Schema::create('ai_provider_cost_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->unsignedInteger('input_microusd_per_1k');
            $table->unsignedInteger('output_microusd_per_1k');
            $table->string('currency', 8)->default('USD');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_provider_cost_rates');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
