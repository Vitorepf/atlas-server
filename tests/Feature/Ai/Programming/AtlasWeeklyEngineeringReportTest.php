<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasWeeklyEngineeringReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasWeeklyEngineeringReportTest extends TestCase
{
    private string $reportPath;

    private string $markdownPath;

    protected function setUp(): void
    {
        parent::setUp();
        $id = (string) Str::uuid();
        $this->reportPath = storage_path("framework/testing/atlas-weekly-report-{$id}.json");
        $this->markdownPath = storage_path("framework/testing/atlas-weekly-report-{$id}.md");
    }

    protected function tearDown(): void
    {
        @File::delete($this->reportPath);
        @File::delete($this->markdownPath);
        parent::tearDown();
    }

    public function test_weekly_report_is_readable_and_feeds_l5_1_without_authorizing_execution(): void
    {
        $payload = app(AtlasWeeklyEngineeringReportService::class)->report([
            'final_report' => $this->finalReportFixture(),
            'weekly_agenda' => $this->weeklyAgendaFixture(),
            'write_report' => true,
            'report_path' => $this->reportPath,
            'write_markdown' => true,
            'markdown_path' => $this->markdownPath,
            'max_words' => 420,
        ]);

        $this->assertSame(AtlasWeeklyEngineeringReportService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'window.readable_in_two_minutes'));
        $this->assertLessThanOrEqual(420, (int) data_get($payload, 'window.word_count'));
        $this->assertSame('ready_for_l5_1', data_get($payload, 'agenda_feed.feed_status'));
        $this->assertSame(2, data_get($payload, 'agenda_feed.agenda_item_count'));
        $this->assertTrue((bool) data_get($payload, 'agenda_feed.operator_approval_required'));
        $this->assertFalse((bool) data_get($payload, 'agenda_feed.auto_execute_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.obra_created'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.merged_to_main'));
        $this->assertFileExists($this->reportPath);
        $this->assertFileExists($this->markdownPath);
        $this->assertStringContainsString('# Atlas Weekly Engineering Report', (string) File::get($this->markdownPath));
    }

    public function test_schedule_lists_weekly_report_before_agenda_time(): void
    {
        config([
            'atlas.loop.weekly_report.enabled' => true,
            'atlas.loop.weekly_report.schedule_enabled' => true,
            'atlas.loop.weekly_report.schedule_day' => 1,
            'atlas.loop.weekly_report.schedule_time' => '05:40',
        ]);

        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:fable:weekly-report --write-report --write-markdown --json', $output);
    }

    /**
     * @return array<string,mixed>
     */
    private function finalReportFixture(): array
    {
        return [
            'status' => 'ready_with_operator_gated_external_proofs',
            'n_x_m' => [
                'merges_per_day' => ['merged_in_digest_window' => 12],
                'measured_cost' => ['digest_cost_coverage_pct_24h' => 80.0, 'total_cost_usd_24h' => 0.42],
                'scorecard' => ['delta' => 0.6],
                'recall' => ['semantic_recall_real' => true, 'semantic_lift_status' => 'positive_lift'],
            ],
            'source_reports' => [
                'morning_digest' => [
                    'status' => 'ok',
                    'sections' => [
                        'canaries' => ['failed_24h' => 1],
                        'cost' => ['coverage_pct_24h' => 80.0],
                        'merges' => ['impact_receipt_coverage_pct_24h' => 96.0],
                    ],
                ],
                'delta_series' => ['status' => 'ok'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function weeklyAgendaFixture(): array
    {
        return [
            'status' => 'ready_for_operator_review',
            'agenda' => [
                [
                    'id' => 'stabilize_red_canaries',
                    'score' => 100,
                    'title' => 'Stabilize red canaries',
                    'recommended_action' => 'Fix-forward the failing canary target.',
                ],
                [
                    'id' => 'triage_auto_fed_backlog',
                    'score' => 72,
                    'title' => 'Triage auto-fed backlog',
                    'recommended_action' => 'Review deduped backlog intents.',
                ],
            ],
            'blocked_candidates' => [
                [
                    'title' => 'Loop->Obra bridge multi-file',
                    'reason' => 'Operator-gated provider receipt remains required.',
                ],
            ],
            'operator_approval' => [
                'required' => true,
                'auto_execute_allowed' => false,
            ],
        ];
    }
}
