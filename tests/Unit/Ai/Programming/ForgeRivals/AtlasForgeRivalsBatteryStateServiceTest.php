<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Battery State Service + Battery Report unit tests.
 *
 * These tests exercise the battery catalogue (battery.json), the JSONL
 * stream (battery.jsonl), the per-case state ladder, the resume contract,
 * and the L1-L5 difficulty aggregator that the report consumes. They never
 * provision a worktree, never invoke a provider, and never depend on the
 * arena corpus' on-disk seeds — they synthesise minimal case dictionaries
 * so they remain green even when the corpus seeds are mid-evolution.
 */
final class AtlasForgeRivalsBatteryStateServiceTest extends TestCase
{
    private string $rootOverride;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootOverride = sys_get_temp_dir().'/atlas-rivals-battery-test-'.Str::lower(Str::random(8));
        config()->set('atlas_rivals.runs_root', $this->rootOverride);
        @mkdir($this->rootOverride, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->wipeDir($this->rootOverride);
        parent::tearDown();
    }

    public function test_battery_initialises_with_all_cases_in_pending_state(): void
    {
        $service = $this->battery();
        $runId = 'battery-init-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'backend-pagination-off-by-one', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'backend-permission-policy-leak', 'task_category' => 'backend', 'difficulty' => 'medium'],
            ['id' => 'architecture-module-boundary-leak', 'task_category' => 'architecture', 'difficulty' => 'hard'],
        ]);

        $battery = $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);

        $this->assertSame(AtlasForgeRivalsBatteryStateService::SCHEMA_VERSION, $battery['schema_version']);
        $this->assertSame(3, $battery['case_count']);
        $this->assertSame('running', $battery['battery_status']);
        $this->assertCount(3, $battery['cases']);
        foreach ($battery['cases'] as $row) {
            $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING, $row['state']);
            $this->assertNull($row['verdict']);
            $this->assertNull($row['started_at']);
            $this->assertNull($row['finished_at']);
            $this->assertSame(0, $row['attempts']);
        }
        $this->assertFileExists($service->batteryJsonPath($runId));
        $this->assertFileExists($service->batteryJsonlPath($runId));
    }

    public function test_difficulty_to_level_maps_easy_medium_hard_to_l1_l3_l5(): void
    {
        $this->assertSame('L1', AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel('easy'));
        $this->assertSame('L3', AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel('medium'));
        $this->assertSame('L5', AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel('hard'));
        $this->assertSame('L3', AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel('unknown-bucket'));
    }

    public function test_difficulty_weight_climbs_with_level(): void
    {
        $l1 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L1');
        $l3 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L3');
        $l5 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L5');
        $this->assertGreaterThan(0, $l1);
        $this->assertGreaterThan($l1, $l3);
        $this->assertGreaterThan($l3, $l5);
    }

    public function test_mark_case_running_then_finished_transitions_state_with_timestamps(): void
    {
        $service = $this->battery();
        $runId = 'battery-state-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
        ]);
        $service->initialize($runId, ['preset' => 'quick', 'mode' => 'local_fake'], $cases);

        $service->markCaseRunning($runId, 'case-a');
        $afterRunning = $service->load($runId);
        $this->assertSame('running', $afterRunning['cases'][0]['state']);
        $this->assertNotNull($afterRunning['cases'][0]['started_at']);
        $this->assertSame(1, $afterRunning['cases'][0]['attempts']);

        $service->markCaseFinished($runId, 'case-a', 'comparable', ['exit_code' => 0]);
        $afterFinish = $service->load($runId);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED, $afterFinish['cases'][0]['state']);
        $this->assertSame('comparable', $afterFinish['cases'][0]['verdict']);
        $this->assertNotNull($afterFinish['cases'][0]['finished_at']);
    }

    public function test_verdict_translates_to_canonical_case_states(): void
    {
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED,
            AtlasForgeRivalsBatteryStateService::verdictToState('comparable'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID,
            AtlasForgeRivalsBatteryStateService::verdictToState('invalid_workspace_after_run'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID,
            AtlasForgeRivalsBatteryStateService::verdictToState('invalid_fixture_blocked'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED,
            AtlasForgeRivalsBatteryStateService::verdictToState('invalid_tests_failed'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED,
            AtlasForgeRivalsBatteryStateService::verdictToState('invalid_provider_timeout'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED,
            AtlasForgeRivalsBatteryStateService::verdictToState('skipped'),
        );
        $this->assertSame(
            AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED,
            AtlasForgeRivalsBatteryStateService::verdictToState('completely-unknown-verdict-from-a-rogue-runner'),
        );
    }

    public function test_resume_initialize_preserves_terminal_state_and_filters_pending(): void
    {
        $service = $this->battery();
        $runId = 'battery-resume-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'case-b', 'task_category' => 'backend', 'difficulty' => 'medium'],
            ['id' => 'case-c', 'task_category' => 'architecture', 'difficulty' => 'hard'],
        ]);
        $service->initialize($runId, ['preset' => 'release'], $cases);

        // Simulate first session completing 2 cases.
        $service->markCaseFinished($runId, 'case-a', 'comparable');
        $service->markCaseFinished($runId, 'case-b', 'invalid_tests_failed');

        // Second session: resume initialise must keep case-a/case-b terminal.
        $afterResume = $service->initialize($runId, ['preset' => 'release'], $cases);
        $this->assertGreaterThanOrEqual(1, $afterResume['resume_count']);
        $byId = [];
        foreach ($afterResume['cases'] as $row) {
            $byId[$row['case_id']] = $row['state'];
        }
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED, $byId['case-a']);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED, $byId['case-b']);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING, $byId['case-c']);

        $pending = $service->pendingCases($afterResume);
        $this->assertSame(['case-c'], $pending);
        $this->assertSame('case-c', $service->nextPendingCase($runId));
    }

    public function test_next_pending_case_returns_null_when_battery_settled(): void
    {
        $service = $this->battery();
        $runId = 'battery-settled-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-x', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
        ]);
        $service->initialize($runId, [], $cases);
        $service->markCaseFinished($runId, 'case-x', 'comparable');

        $this->assertNull($service->nextPendingCase($runId));
        $this->assertSame([], $service->pendingCases($runId));
    }

    public function test_skip_marks_case_skipped_and_never_re_enters_pending(): void
    {
        $service = $this->battery();
        $runId = 'battery-skip-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-x', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
        ]);
        $service->initialize($runId, [], $cases);
        $service->markCaseSkipped($runId, 'case-x', 'operator_aborted_runbook');

        $battery = $service->load($runId);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED, $battery['cases'][0]['state']);
        $this->assertSame('operator_aborted_runbook', $battery['cases'][0]['skip_reason']);
        $this->assertSame('skipped', $battery['cases'][0]['verdict']);
        $this->assertSame([], $service->pendingCases($runId));
    }

    public function test_battery_jsonl_appends_canonical_events_per_state_change(): void
    {
        $service = $this->battery();
        $runId = 'battery-jsonl-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'case-b', 'task_category' => 'backend', 'difficulty' => 'medium'],
        ]);
        $service->initialize($runId, ['preset' => 'release'], $cases);
        $service->markCaseRunning($runId, 'case-a');
        $service->markCaseFinished($runId, 'case-a', 'comparable');
        $service->markCaseFinished($runId, 'case-b', 'invalid_tests_failed');
        $service->finalize($runId, [
            'aggregate_verdict' => 'invalid_tests_failed',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $lines = file($service->batteryJsonlPath($runId), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $kinds = array_values(array_map(static function (string $line): string {
            $row = json_decode($line, true);

            return is_array($row) ? (string) ($row['kind'] ?? '') : '';
        }, $lines));
        $this->assertContains('battery_started', $kinds);
        $this->assertContains('case_state_changed', $kinds);
        $this->assertContains('case_finished', $kinds);
        $this->assertContains('battery_finished', $kinds);
    }

    public function test_finalize_reports_paused_when_cases_remain_pending(): void
    {
        $service = $this->battery();
        $runId = 'battery-pause-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-done', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'case-todo', 'task_category' => 'backend', 'difficulty' => 'medium'],
        ]);
        $service->initialize($runId, ['preset' => 'release'], $cases);
        $service->markCaseFinished($runId, 'case-done', 'comparable');
        $final = $service->finalize($runId, [
            'aggregate_verdict' => 'battery_partial',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $this->assertSame('paused', $final['battery_status']);
        $this->assertFalse($final['claim_ready']);
    }

    public function test_snapshot_carries_state_difficulty_and_category_counters(): void
    {
        $service = $this->battery();
        $runId = 'battery-snapshot-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'case-a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'case-b', 'task_category' => 'backend', 'difficulty' => 'medium'],
            ['id' => 'case-c', 'task_category' => 'architecture', 'difficulty' => 'hard'],
        ]);
        $service->initialize($runId, ['preset' => 'release'], $cases);
        $service->markCaseFinished($runId, 'case-a', 'comparable');
        $service->markCaseFinished($runId, 'case-b', 'invalid_workspace_after_run');

        $snap = $service->snapshot($runId);
        $this->assertTrue($snap['exists']);
        $this->assertSame(3, $snap['case_count']);
        $this->assertSame(1, $snap['state_counts'][AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED]);
        $this->assertSame(1, $snap['state_counts'][AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID]);
        $this->assertSame(1, $snap['state_counts'][AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING]);
        $this->assertSame(1, $snap['pending_case_count']);
        $this->assertSame(2, $snap['terminal_case_count']);
        $this->assertSame('case-c', $snap['next_case_id']);
        $this->assertSame(['L1' => 1, 'L3' => 1, 'L5' => 1], $snap['difficulty_counts']);
        $this->assertSame(['bugfix' => 1, 'backend' => 1, 'architecture' => 1], $snap['category_counts']);
    }

    public function test_battery_report_aggregates_by_category_and_l1_l5(): void
    {
        $service = $this->battery();
        $runId = 'battery-report-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'b-a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'b-b', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'be-c', 'task_category' => 'backend', 'difficulty' => 'medium'],
            ['id' => 'arch-d', 'task_category' => 'architecture', 'difficulty' => 'hard'],
        ]);
        $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);
        $service->markCaseFinished($runId, 'b-a', 'comparable');
        $service->markCaseFinished($runId, 'b-b', 'comparable');
        $service->markCaseFinished($runId, 'be-c', 'invalid_tests_failed');
        $service->markCaseFinished($runId, 'arch-d', 'comparable');
        $service->finalize($runId, [
            'aggregate_verdict' => 'invalid_tests_failed',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $report = $this->reporter()->render(['run_id' => $runId]);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(4, $report['case_count']);
        $this->assertFalse($report['claim_ready']);

        $byCategory = [];
        foreach ($report['category_aggregate'] as $row) {
            $byCategory[$row['key']] = $row;
        }
        $this->assertSame(2, $byCategory['bugfix']['completed']);
        $this->assertSame(0, $byCategory['bugfix']['failed']);
        $this->assertSame(1, $byCategory['backend']['failed']);
        $this->assertSame(1, $byCategory['architecture']['completed']);

        $byLevel = [];
        foreach ($report['difficulty_aggregate'] as $row) {
            $byLevel[$row['key']] = $row;
        }
        $this->assertSame(2, $byLevel['L1']['completed']);
        $this->assertSame(0, $byLevel['L1']['failed']);
        $this->assertSame(1, $byLevel['L3']['failed']);
        $this->assertSame(1, $byLevel['L5']['completed']);

        // Weighted score: completed_weight / total_weight.
        $w1 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L1');
        $w3 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L3');
        $w5 = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight('L5');
        $expectedScore = round(((2 * $w1) + (0 * $w3) + (1 * $w5)) / ((2 * $w1) + (1 * $w3) + (1 * $w5)) * 100, 2);
        $this->assertSame($expectedScore, $report['weighted_score']['score_percent']);

        $this->assertFileExists($report['report_path']);
        $markdown = (string) file_get_contents($report['report_path']);
        $this->assertStringContainsString('Atlas Forge Rivals · Battery Report', $markdown);
        $this->assertStringContainsString('| L1 |', $markdown);
        $this->assertStringContainsString('| L3 |', $markdown);
        $this->assertStringContainsString('| L5 |', $markdown);
        $this->assertStringContainsString('claim_ready', $markdown);
        $this->assertStringContainsString('external_rivals_certification', $markdown);
    }

    public function test_battery_report_keeps_claim_ready_false_when_any_case_invalid_or_failed_or_skipped(): void
    {
        $service = $this->battery();
        $runId = 'battery-claim-'.Str::lower(Str::random(6));
        $cases = $this->syntheticCases([
            ['id' => 'a', 'task_category' => 'bugfix', 'difficulty' => 'easy'],
            ['id' => 'b', 'task_category' => 'backend', 'difficulty' => 'medium'],
        ]);
        $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);
        $service->markCaseFinished($runId, 'a', 'comparable');
        $service->markCaseSkipped($runId, 'b', 'operator_aborted');
        $report = $this->reporter()->render(['run_id' => $runId]);
        $this->assertFalse($report['claim_ready']);
        $this->assertTrue($report['separated_from_external_rivals_certification']);
        $this->assertTrue($report['human_review_required']);
    }

    public function test_battery_report_blocks_when_run_id_missing(): void
    {
        $report = $this->reporter()->render(['run_id' => '']);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('run_id_required', $report['blockers']);
    }

    public function test_battery_report_blocks_when_battery_does_not_exist_yet(): void
    {
        $report = $this->reporter()->render(['run_id' => 'no-such-run-id-'.Str::lower(Str::random(6))]);
        $this->assertSame('blocked', $report['status']);
        $joined = implode('|', $report['blockers'] ?? []);
        $this->assertStringContainsString('battery_not_found', $joined);
    }

    private function battery(): AtlasForgeRivalsBatteryStateService
    {
        return app(AtlasForgeRivalsBatteryStateService::class);
    }

    private function reporter(): AtlasForgeRivalsBatteryReportService
    {
        return app(AtlasForgeRivalsBatteryReportService::class);
    }

    /**
     * Build minimal synthetic cases that match the adapter shape — enough
     * for battery state tests, without touching the corpus or worktrees.
     *
     * @param  list<array{id:string, task_category:string, difficulty:string}>  $cases
     * @return list<array<string,mixed>>
     */
    private function syntheticCases(array $cases): array
    {
        return array_values(array_map(static function (array $c): array {
            $level = AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($c['difficulty']);

            return [
                'id' => (string) $c['id'],
                'case_source' => 'provider_arena_corpus',
                'case_set' => 'release',
                'task_category' => (string) $c['task_category'],
                'category' => (string) $c['task_category'],
                'difficulty' => (string) $c['difficulty'],
                'difficulty_level' => $level,
                'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
            ];
        }, $cases));
    }

    private function wipeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->wipeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
