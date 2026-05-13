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

    public function test_command_exposes_rivals_review_scores_as_json(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDRIVALS1',
            'cmd-inbox-rivals',
            'record_rivals_review',
            'operator_cli',
            'record_due_rivals_strategy_reviews',
            [],
            [
                'schema_version' => 'atlas.inbox_action.rivals_review.v1',
                'recorded_review_id' => 'review-rivals-command',
                'case_id' => 'case-rivals-command',
                'horizon_days' => 30,
                'scores' => ['regret' => 7, 'alignment' => 94, 'agency' => 89],
                'remaining_due_review_count' => 0,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'record_rivals_review',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'inbox_actions.rivals_review_recorded_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.rivals_review_with_scores_count'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('review-rivals-command', data_get($payload, 'inbox_actions.recent_events.0.rivals_review_id'));
        $this->assertSame(89, data_get($payload, 'inbox_actions.recent_events.0.rivals_agency_score'));
    }

    public function test_command_exposes_provider_cost_rate_action_as_json(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDCOST001',
            'cmd-inbox-cost-rate',
            'configure_provider_cost_rates',
            'operator_cli',
            'configure_provider_cost_rates',
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
                'applied' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'configure_provider_cost_rates',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'inbox_actions.provider_cost_rate_action_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.provider_cost_rate_applied_count'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('codex_cli', data_get($payload, 'inbox_actions.recent_events.0.provider_cost_rate_provider'));
        $this->assertSame('gpt-5.2', data_get($payload, 'inbox_actions.recent_events.0.provider_cost_rate_model'));
        $this->assertSame(480, data_get($payload, 'inbox_actions.recent_events.0.provider_cost_rate_output_microusd'));
    }

    public function test_command_exposes_retrieval_regression_review_as_json(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDRETRIEVAL1',
            'cmd-inbox-retrieval-regression',
            'review_retrieval_regression',
            'operator_cli',
            'open_memory_retrieval_regression_review',
            [],
            [],
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_regression_review.v1',
                'decision' => 'needs_more_evidence',
                'reviewed' => true,
                'report_hash' => hash('sha256', 'retrieval-report-command'),
                'latest_snapshot_hash' => hash('sha256', 'retrieval-latest-command'),
                'previous_snapshot_hash' => hash('sha256', 'retrieval-previous-command'),
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_retrieval_regression',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'inbox_actions.retrieval_regression_review_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.retrieval_regression_reviewed_count'));
        $this->assertSame(['needs_more_evidence' => 1], data_get($payload, 'inbox_actions.retrieval_regression_decision_counts'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('memory_retrieval_regression_review_recorded', data_get($payload, 'inbox_actions.review_signal.reasons.0'));
        $this->assertSame('needs_more_evidence', data_get($payload, 'inbox_actions.recent_events.0.retrieval_regression_decision'));
        $this->assertTrue((bool) data_get($payload, 'inbox_actions.recent_events.0.retrieval_regression_no_runtime_execution'));
        $this->assertTrue((bool) data_get($payload, 'inbox_actions.recent_events.0.retrieval_regression_no_policy_patch'));
    }

    public function test_command_exposes_retrieval_shadow_scope_review_receipt_as_json(): void
    {
        $receiptHash = hash('sha256', 'retrieval-shadow-scope-command-receipt');

        $this->recordInboxAction(
            '01HINBOXACTIONCMDSHADOW01',
            'cmd-inbox-retrieval-shadow-scope',
            'review_retrieval_shadow_scope',
            'operator_cli',
            'review_retrieval_shadow_scope',
            [],
            [],
            [],
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_shadow_scope_review.v1',
                'decision' => 'needs_more_evidence',
                'reviewed' => true,
                'plan_hash' => hash('sha256', 'retrieval-shadow-scope-command-plan'),
                'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                'decision_receipt_hash' => $receiptHash,
                'decision_receipt' => [
                    'schema_version' => 'atlas.memory_retrieval_shadow_scope_decision_receipt.v1',
                    'receipt_hash' => $receiptHash,
                    'shadow_execution_allowed_now' => false,
                ],
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
                'no_provider_call' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_retrieval_shadow_scope',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'inbox_actions.retrieval_shadow_scope_review_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.retrieval_shadow_scope_reviewed_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.retrieval_shadow_scope_decision_receipt_count'));
        $this->assertSame(0, data_get($payload, 'inbox_actions.retrieval_shadow_scope_runtime_allowed_count'));
        $this->assertSame(['needs_more_evidence' => 1], data_get($payload, 'inbox_actions.retrieval_shadow_scope_decision_counts'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('memory_retrieval_shadow_scope_review_recorded', data_get($payload, 'inbox_actions.review_signal.reasons.0'));
        $this->assertSame('needs_more_evidence', data_get($payload, 'inbox_actions.recent_events.0.retrieval_shadow_scope_decision'));
        $this->assertSame($receiptHash, data_get($payload, 'inbox_actions.recent_events.0.retrieval_shadow_scope_decision_receipt_hash'));
        $this->assertFalse((bool) data_get($payload, 'inbox_actions.recent_events.0.retrieval_shadow_scope_shadow_execution_allowed_now'));
        $this->assertTrue((bool) data_get($payload, 'inbox_actions.recent_events.0.retrieval_shadow_scope_no_provider_call'));
    }

    public function test_command_exposes_external_vector_rag_preflight_review_receipt_as_json(): void
    {
        $receiptHash = hash('sha256', 'external-vector-rag-preflight-command-receipt');

        $this->recordInboxAction(
            '01HINBOXACTIONCMDEXTERNALVECTOR01',
            'cmd-inbox-external-vector-rag-preflight',
            'review_external_vector_rag_preflight',
            'operator_cli',
            'review_external_vector_rag_preflight',
            [],
            [],
            [],
            [],
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.external_vector_rag_preflight_review.v1',
                'decision' => 'approved_scope',
                'reviewed' => true,
                'scope_approved' => true,
                'preflight_hash' => hash('sha256', 'external-vector-rag-command-preflight'),
                'decision_receipt_hash' => $receiptHash,
                'decision_receipt' => [
                    'schema_version' => 'atlas.external_vector_rag.preflight_decision_receipt.v1',
                    'receipt_hash' => $receiptHash,
                    'embedding_generation_allowed_now' => false,
                    'external_vector_store_write_allowed_now' => false,
                    'constellation_promotion_allowed_now' => false,
                ],
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
                'no_provider_call' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_external_vector_rag_preflight',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'inbox_actions.external_vector_rag_preflight_review_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.external_vector_rag_preflight_reviewed_count'));
        $this->assertSame(1, data_get($payload, 'inbox_actions.external_vector_rag_preflight_decision_receipt_count'));
        $this->assertSame(0, data_get($payload, 'inbox_actions.external_vector_rag_preflight_unsafe_activation_count'));
        $this->assertSame(['approved_scope' => 1], data_get($payload, 'inbox_actions.external_vector_rag_preflight_decision_counts'));
        $this->assertSame('ok', data_get($payload, 'inbox_actions.review_signal.status'));
        $this->assertSame('external_vector_rag_preflight_review_recorded', data_get($payload, 'inbox_actions.review_signal.reasons.0'));
        $this->assertSame('approved_scope', data_get($payload, 'inbox_actions.recent_events.0.external_vector_rag_preflight_decision'));
        $this->assertSame($receiptHash, data_get($payload, 'inbox_actions.recent_events.0.external_vector_rag_preflight_decision_receipt_hash'));
        $this->assertFalse((bool) data_get($payload, 'inbox_actions.recent_events.0.external_vector_rag_preflight_embedding_allowed_now'));
        $this->assertFalse((bool) data_get($payload, 'inbox_actions.recent_events.0.external_vector_rag_preflight_vector_write_allowed_now'));
        $this->assertFalse((bool) data_get($payload, 'inbox_actions.recent_events.0.external_vector_rag_preflight_constellation_allowed_now'));
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
        $this->assertStringNotContainsString('Provider cost-rate actions', $output);
    }

    public function test_command_human_output_includes_provider_cost_rate_summary_when_configured(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANCOST01',
            'cmd-inbox-human-cost-rate',
            'configure_provider_cost_rates',
            'operator_cli',
            'configure_provider_cost_rates',
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
                'effective_from' => '2026-05-01',
                'effective_until' => '2026-06-01',
                'applied' => true,
            ],
            ['id' => 'rate-codex-cli-gpt-52'],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'configure_provider_cost_rates',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Provider cost-rate actions', $output);
        $this->assertStringContainsString('Applied cost-rate actions', $output);
        $this->assertStringContainsString('Pending cost-rate actions', $output);
        $this->assertStringContainsString('Cost-rate completion', $output);
        $this->assertStringContainsString('1/1 applied', $output);
        $this->assertStringContainsString('Provider counts', $output);
        $this->assertStringContainsString('Model counts', $output);
        $this->assertStringContainsString('Provider cost-rate events', $output);
        $this->assertStringContainsString('rate id', $output);
        $this->assertStringContainsString('rate-codex-cli-gpt-52', $output);
        $this->assertStringContainsString('codex_cli', $output);
        $this->assertStringContainsString('codex_cli:gpt-5.2', $output);
        $this->assertMatchesRegularExpression('/\|\s+yes\s+\|/', $output);
        $this->assertStringContainsString('120', $output);
        $this->assertStringContainsString('480', $output);
        $this->assertStringContainsString('USD', $output);
        $this->assertStringContainsString('effective from', $output);
        $this->assertStringContainsString('effective until', $output);
        $this->assertStringContainsString('2026-05-01', $output);
        $this->assertStringContainsString('2026-06-01', $output);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('Recommended action', $output);
        $this->assertStringContainsString('Cost-rate review signal', $output);
        $this->assertStringContainsString('Cost-rate review severity', $output);
        $this->assertStringContainsString('Cost-rate review required', $output);
        $this->assertStringContainsString('Cost-rate recommended action', $output);
        $this->assertStringContainsString('Cost-rate review reasons', $output);
        $this->assertStringContainsString('provider_cost_rates_configured', $output);
    }

    public function test_command_human_output_includes_retrieval_regression_summary_when_reviewed(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANRETRIEVAL1',
            'cmd-inbox-human-retrieval-regression',
            'review_retrieval_regression',
            'operator_cli',
            'open_memory_retrieval_regression_review',
            [],
            [],
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_regression_review.v1',
                'decision' => 'accepted_regression',
                'reviewed' => true,
                'report_hash' => 'report-hash-human',
                'latest_snapshot_hash' => 'latest-hash-human',
                'previous_snapshot_hash' => 'previous-hash-human',
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_retrieval_regression',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Memory Retrieval Regression Reviews', $output);
        $this->assertStringContainsString('Retrieval regression reviews', $output);
        $this->assertStringContainsString('Retrieval regression completion', $output);
        $this->assertStringContainsString('1/1 reviewed', $output);
        $this->assertStringContainsString('accepted_regression', $output);
        $this->assertStringContainsString('report-hash-human', $output);
        $this->assertStringContainsString('latest-hash-human', $output);
        $this->assertStringContainsString('previous-hash-human', $output);
        $this->assertStringContainsString('memory_retrieval_regression_review_recorded', $output);
    }

    public function test_command_human_output_includes_retrieval_shadow_scope_summary_when_receipted(): void
    {
        $receiptHash = hash('sha256', 'retrieval-shadow-scope-human-receipt');

        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANSHADOW01',
            'cmd-inbox-human-retrieval-shadow-scope',
            'review_retrieval_shadow_scope',
            'operator_cli',
            'review_retrieval_shadow_scope',
            [],
            [],
            [],
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_shadow_scope_review.v1',
                'decision' => 'approved_scope',
                'reviewed' => true,
                'plan_hash' => 'shadow-plan-hash-human',
                'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                'decision_receipt_hash' => $receiptHash,
                'decision_receipt' => [
                    'schema_version' => 'atlas.memory_retrieval_shadow_scope_decision_receipt.v1',
                    'receipt_hash' => $receiptHash,
                    'shadow_execution_allowed_now' => false,
                ],
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
                'no_provider_call' => true,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'review_retrieval_shadow_scope',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Memory Retrieval Shadow Scope Reviews', $output);
        $this->assertStringContainsString('Retrieval shadow-scope reviews', $output);
        $this->assertStringContainsString('Shadow-scope decision receipts', $output);
        $this->assertStringContainsString('Shadow execution allowed now', $output);
        $this->assertStringContainsString('1/1 reviewed, 1/1 receipted', $output);
        $this->assertStringContainsString('approved_scope', $output);
        $this->assertStringContainsString($receiptHash, $output);
        $this->assertStringContainsString('shadow-plan-hash-human', $output);
        $this->assertStringContainsString('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', $output);
        $this->assertStringContainsString('memory_retrieval_shadow_scope_review_recorded', $output);
    }

    public function test_command_human_output_flags_provider_cost_rate_preview_without_applied_rate(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANCOST02',
            'cmd-inbox-human-cost-rate-preview',
            'configure_provider_cost_rates',
            'operator_cli',
            'configure_provider_cost_rates',
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'currency' => 'USD',
                'applied' => false,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'configure_provider_cost_rates',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Provider Cost Rates', $output);
        $this->assertStringContainsString('needs review', $output);
        $this->assertStringContainsString('Provider cost-rate actions', $output);
        $this->assertStringContainsString('Applied cost-rate actions', $output);
        $this->assertStringContainsString('Pending cost-rate actions', $output);
        $this->assertStringContainsString('Cost-rate completion', $output);
        $this->assertStringContainsString('0/1 applied', $output);
        $this->assertStringContainsString('Provider cost-rate events', $output);
        $this->assertStringContainsString('codex_cli', $output);
        $this->assertStringContainsString('codex_cli:gpt-5.2', $output);
        $this->assertMatchesRegularExpression('/\|\s+no\s+\|/', $output);
        $this->assertStringContainsString('configure_provider_cost_rates_action_without_applied_rate', $output);
        $this->assertStringContainsString('Cost-rate review severity', $output);
        $this->assertStringContainsString('Cost-rate review required', $output);
        $this->assertStringContainsString('Cost-rate recommended action', $output);
        $this->assertStringContainsString('configure_provider_cost_rates', $output);
    }

    public function test_command_human_output_summarizes_mixed_provider_cost_rate_completion(): void
    {
        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANCOST03',
            'cmd-inbox-human-cost-rate-applied',
            'configure_provider_cost_rates',
            'operator_cli',
            'configure_provider_cost_rates',
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
                'applied' => true,
            ],
        );
        $this->recordInboxAction(
            '01HINBOXACTIONCMDHUMANCOST04',
            'cmd-inbox-human-cost-rate-pending',
            'configure_provider_cost_rates',
            'operator_cli',
            'configure_provider_cost_rates',
            [],
            [],
            [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.3',
                'currency' => 'USD',
                'applied' => false,
            ],
        );

        $exit = Artisan::call('atlas:ai:inbox-action-report', [
            '--hours' => 24,
            '--action' => 'configure_provider_cost_rates',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('needs review', $output);
        $this->assertStringContainsString('Cost-rate completion', $output);
        $this->assertStringContainsString('1/2 applied', $output);
        $this->assertStringContainsString('Provider cost-rate actions', $output);
        $this->assertStringContainsString('Applied cost-rate actions', $output);
        $this->assertStringContainsString('Pending cost-rate actions', $output);
        $this->assertStringContainsString('Cost-rate review required', $output);
        $this->assertStringContainsString('codex_cli:gpt-5.2', $output);
        $this->assertStringContainsString('codex_cli:gpt-5.3', $output);
        $this->assertStringContainsString('configure_provider_cost_rates_action_without_applied_rate', $output);
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
    private function recordInboxAction(
        string $eventId,
        string $inboxItemId,
        string $action,
        string $actorType,
        string $recommendedAction,
        array $diffRefs,
        array $rivalsReviewAction = [],
        array $providerCostRateAction = [],
        array $upsertedRate = [],
        array $retrievalRegressionReviewAction = [],
        array $retrievalShadowScopeReviewAction = [],
        array $externalVectorRagPreflightReviewAction = [],
    ): void {
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
                    'rivals_review_action' => $rivalsReviewAction,
                    'provider_cost_rate_action' => $providerCostRateAction,
                    'upserted_rate' => $upsertedRate,
                    'retrieval_regression_review_action' => $retrievalRegressionReviewAction,
                    'retrieval_shadow_scope_review_action' => $retrievalShadowScopeReviewAction,
                    'external_vector_rag_preflight_review_action' => $externalVectorRagPreflightReviewAction,
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
