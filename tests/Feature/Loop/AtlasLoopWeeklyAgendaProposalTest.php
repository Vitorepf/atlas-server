<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWeeklyAgendaProposalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopWeeklyAgendaProposalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-12 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_weekly_agenda_prioritizes_resolved_loop_signals_without_execution(): void
    {
        $payload = app(AtlasLoopWeeklyAgendaProposalService::class)->propose($this->fixtureOptions());

        $this->assertSame('atlas.loop.weekly_agenda_proposal.v1', $payload['schema_version']);
        $this->assertSame('ready_for_operator_review', $payload['status']);
        $this->assertSame('stabilize_red_canaries', $payload['agenda'][0]['id']);
        $this->assertContains('restore_cost_coverage', array_column($payload['agenda'], 'id'));
        $this->assertContains('drain_certified_proposals', array_column($payload['agenda'], 'id'));
        $this->assertContains('raise_pipeline_score', array_column($payload['agenda'], 'id'));
        $this->assertSame('l5_2_loop_to_obra_bridge', $payload['blocked_candidates'][0]['id']);
        $this->assertTrue((bool) data_get($payload, 'operator_approval.required'));
        $this->assertFalse((bool) data_get($payload, 'operator_approval.auto_execute_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.obra_created'));
        $this->assertTrue((bool) data_get($payload, 'backlog_proposal_payload.human_review_required'));
        $this->assertFalse((bool) data_get($payload, 'backlog_proposal_payload.autopromotion_requested'));
    }

    public function test_weekly_agenda_can_create_backlog_draft_for_operator_review_only(): void
    {
        Storage::fake('local');

        $payload = app(AtlasLoopWeeklyAgendaProposalService::class)->propose(array_merge(
            $this->fixtureOptions(),
            ['create_proposal' => true],
        ));

        $proposalId = (string) data_get($payload, 'operator_approval.created_proposal_id');

        $this->assertNotSame('', $proposalId);
        $this->assertSame('draft', data_get($payload, 'created_backlog_proposal.status'));
        $this->assertSame('ready', data_get($payload, 'created_backlog_proposal.proposal_packet_status'));
        $this->assertSame('evaluate_proposal_through_power_gate', data_get($payload, 'created_backlog_proposal.next_safe_action'));
        $this->assertNull(data_get($payload, 'created_backlog_proposal.linked_obra_id'));
        $this->assertFalse((bool) data_get($payload, 'created_backlog_proposal.external_provider_call'));
        $this->assertFalse((bool) data_get($payload, 'created_backlog_proposal.provider_tokens_spent'));
        $this->assertFalse((bool) data_get($payload, 'created_backlog_proposal.auto_fast_path_executed'));
        Storage::disk('local')->assertExists('atlas/self-improvement/proposal-backlog/'.$proposalId.'.json');
    }

    public function test_weekly_agenda_records_operator_approved_execution_without_auto_running_items(): void
    {
        Storage::fake('local');

        $payload = app(AtlasLoopWeeklyAgendaProposalService::class)->propose(array_merge(
            $this->fixtureOptions(),
            [
                'execute_approved' => true,
                'operator_approval' => 'operator approves Fable L5-1 weekly agenda execution for this run',
            ],
        ));

        $receiptPath = (string) data_get($payload, 'approved_execution.receipt_path');
        $proposalId = (string) data_get($payload, 'approved_execution.proposal_id');

        $this->assertSame('ready_for_operator_review', $payload['status']);
        $this->assertNotSame('', $proposalId);
        $this->assertSame('executed', data_get($payload, 'approved_execution.status'));
        $this->assertTrue((bool) data_get($payload, 'approved_execution.operator_approval.approved'));
        $this->assertTrue((bool) data_get($payload, 'approved_execution.execution_effect.backlog_proposal_created'));
        $this->assertTrue((bool) data_get($payload, 'approved_execution.execution_effect.proposal_evaluated'));
        $this->assertTrue((bool) data_get($payload, 'approved_execution.execution_effect.proposal_prioritized'));
        $this->assertFalse((bool) data_get($payload, 'approved_execution.execution_effect.agenda_items_auto_executed'));
        $this->assertFalse((bool) data_get($payload, 'approved_execution.claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'approved_execution.claim_policy.obra_created'));
        $this->assertFalse((bool) data_get($payload, 'approved_execution.claim_policy.merged_to_main'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.auto_execution_started'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.operator_approval_recorded'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.approved_execution_receipt_written'));
        Storage::disk('local')->assertExists($receiptPath);
    }

    public function test_weekly_agenda_consumes_latest_weekly_report_when_present(): void
    {
        $path = storage_path('framework/testing/atlas-weekly-report-for-agenda-'.(string) Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => 'atlas.weekly_engineering_report.v1',
            'status' => 'ready',
            'window' => [
                'readable_in_two_minutes' => true,
                'word_count' => 180,
            ],
            'agenda_feed' => [
                'feed_status' => 'ready_for_l5_1',
                'agenda_item_count' => 3,
                'operator_approval_required' => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            config(['atlas.loop.weekly_report.report_path' => $path]);
            $payload = app(AtlasLoopWeeklyAgendaProposalService::class)->propose($this->fixtureOptions());

            $this->assertSame('ready', data_get($payload, 'source_summary.weekly_report.status'));
            $this->assertSame('ready_for_l5_1', data_get($payload, 'source_summary.weekly_report.feed_status'));
            $this->assertSame(3, data_get($payload, 'source_summary.weekly_report.agenda_item_count'));
            $this->assertSame('ready_for_l5_1', data_get($payload, 'backlog_proposal_payload.weekly_report_feed.feed_status'));
        } finally {
            @File::delete($path);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureOptions(): array
    {
        return [
            'hours' => 168,
            'max_items' => 5,
            'digest' => [
                'status' => 'ok',
                'headline' => '20 merges, 3 red canaries',
                'sections' => [
                    'funnel' => [
                        'stages' => ['merged' => 20, 'drainable' => 7],
                        'branches' => ['retired_stale' => 107],
                        'conversion' => ['drainable_remaining' => 7],
                        'verdict' => '20 merges em main; 7 ainda drenáveis',
                    ],
                    'canaries' => [
                        'ran_24h' => 3,
                        'failed_24h' => 3,
                        'latest_failed' => [
                            ['target_path' => 'tests/Feature/Loop/ExampleTest.php'],
                        ],
                    ],
                    'cost' => [
                        'events_24h' => 4,
                        'measured_events_24h' => 0,
                        'coverage_pct_24h' => 0.0,
                        'total_cost_usd_24h' => 0.0,
                    ],
                    'operator_review' => [
                        'pending_parked_for_review' => 1,
                    ],
                ],
            ],
            'delta_report' => [
                'status' => 'ok',
                'today' => [
                    'metrics' => [
                        'scorecard_overall' => 8.12,
                        'pipeline_score' => 5.99,
                        'loop_proposals_merged_to_main' => 20,
                        'loop_impact_receipt_coverage_pct' => 75.0,
                        'semantic_recall_real' => true,
                    ],
                ],
            ],
            'scorecard' => [
                'ok' => false,
                'report' => [
                    'subsystem_count' => 73,
                    'score' => [
                        'overall_out_of_10' => 8.12,
                        'dimensions' => [
                            'code' => ['score_out_of_10' => 10.0],
                            'doc' => ['score_out_of_10' => 8.37],
                            'pipeline' => ['score_out_of_10' => 5.99],
                        ],
                    ],
                    'claim_policy' => [
                        'benchmark_claim_allowed' => false,
                    ],
                ],
            ],
            'backlog_feed' => [
                'status' => 'dry_run',
                'candidate_count' => 2,
                'actions_count' => 2,
                'writes_enabled' => false,
                'source_counts' => ['loss_observer' => 1, 'campaign_residuals' => 1],
            ],
            'final_capture' => [
                'status' => 'ready',
                'final_report' => [
                    'status' => 'ready',
                    'report_status' => 'ready_with_operator_gated_external_proofs',
                ],
                'cold_session_recovery' => [
                    'recoverable_from_recorded_artifacts_only' => true,
                ],
                '_loaded_final_report_summary' => [
                    'status' => 'ready_with_operator_gated_external_proofs',
                    'l4_10_real_execution_claim_allowed' => false,
                    'forge_l4_10_status' => 'real_execution_blocked',
                ],
            ],
        ];
    }
}
