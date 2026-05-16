<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsNextService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsStatusService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Matrix Runner v1 contract tests.
 *
 * The matrix corpus is 8 categories × 5 difficulty levels = 40 cases. These
 * tests synthesise the 40-case shape (we do not edit the corpus here — that
 * is Claude 1's frontline) and drive the runner / status / next / report
 * pipeline over it. No external provider is invoked; the BatteryStateService
 * is used directly so we can validate weight propagation, resume safety,
 * status counters and the next-command advisor against the canonical
 * battery.json without spinning up worktrees.
 *
 * Goals:
 *   - 40 synthetic cases (8 × 5) reach battery.json with state=pending and
 *     all four canonical weights persisted: difficulty_weight,
 *     difficulty_score, planning_weight, execution_weight.
 *   - Status returns the canonical counters block:
 *     total/passed/failed/invalid/running/pending/skipped/remaining.
 *   - Resume after a partial run keeps terminal cases untouched and only
 *     iterates pending cases.
 *   - `next` advisor returns `resume` while cases are pending and
 *     `battery-report` once every case is terminal.
 *   - BatteryReportService exposes planning_score, execution_score and
 *     difficulty_score blocks in addition to the L1-L5 weighted score.
 */
final class AtlasForgeRivalsMatrixRunnerTest extends TestCase
{
    private string $rootOverride;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootOverride = sys_get_temp_dir().'/atlas-rivals-matrix-test-'.Str::lower(Str::random(8));
        config()->set('atlas_rivals.runs_root', $this->rootOverride);
        @mkdir($this->rootOverride, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->wipeDir($this->rootOverride);
        parent::tearDown();
    }

    public function test_matrix_battery_initialises_with_forty_pending_cases_and_canonical_weights(): void
    {
        $service = $this->battery();
        $runId = 'matrix-init-'.Str::lower(Str::random(6));
        $cases = $this->synthesise40Cases();
        $this->assertCount(40, $cases);

        $battery = $service->initialize($runId, [
            'preset' => 'release',
            'case_set' => 'matrix',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);

        $this->assertSame(40, $battery['case_count']);
        $this->assertCount(40, $battery['cases']);

        // Every case has the four canonical weights persisted.
        foreach ($battery['cases'] as $row) {
            $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING, $row['state']);
            $this->assertIsFloat($row['difficulty_weight']);
            $this->assertIsFloat($row['difficulty_score']);
            $this->assertIsFloat($row['planning_weight']);
            $this->assertIsFloat($row['execution_weight']);
            $this->assertGreaterThan(0, $row['planning_weight']);
            $this->assertGreaterThan(0, $row['execution_weight']);
            $this->assertContains(
                $row['difficulty_level'],
                AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS,
            );
        }

        $snapshot = $service->snapshot($runId);
        // 8 distinct categories × 5 distinct difficulty levels.
        $this->assertSame(8, count(array_filter($snapshot['category_counts'], static fn (int $c): bool => $c > 0)));
        $this->assertSame(5, count(array_filter($snapshot['difficulty_counts'], static fn (int $c): bool => $c > 0)));
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $this->assertSame(
                8,
                (int) ($snapshot['difficulty_counts'][$level] ?? 0),
                "matrix must declare exactly 8 cases per difficulty level ({$level}).",
            );
        }
    }

    public function test_status_progress_counters_track_passed_failed_invalid_running_pending_skipped_remaining(): void
    {
        $service = $this->battery();
        $runId = 'matrix-status-'.Str::lower(Str::random(6));
        $service->initialize($runId, ['preset' => 'release'], $this->synthesise40Cases());

        // Settle one case per outcome we want to count.
        $service->markCaseFinished($runId, 'cat-backend_logic-L1', 'comparable');                  // passed
        $service->markCaseFinished($runId, 'cat-frontend_ui-L1', 'invalid_tests_failed');           // failed
        $service->markCaseFinished($runId, 'cat-architecture-L1', 'invalid_workspace_after_run');   // invalid
        $service->markCaseSkipped($runId, 'cat-refactor-L1', 'operator_aborted_runbook');           // skipped
        $service->markCaseRunning($runId, 'cat-realistic_bugfix-L1');                               // running

        $status = $this->statusService()->status(['run_id' => $runId]);
        $progress = $status['progress'] ?? [];

        $this->assertSame(40, $progress['total']);
        $this->assertSame(1, $progress['passed']);
        $this->assertSame(1, $progress['failed']);
        $this->assertSame(1, $progress['invalid']);
        $this->assertSame(1, $progress['skipped']);
        $this->assertSame(1, $progress['running']);
        $this->assertSame(35, $progress['pending']);
        $this->assertSame(36, $progress['remaining']);

        $this->assertStringContainsString('resume', (string) $status['next_command']);
    }

    public function test_status_returns_zero_counters_when_no_battery_exists(): void
    {
        $runId = 'matrix-empty-'.Str::lower(Str::random(6));
        $status = $this->statusService()->status(['run_id' => $runId]);
        $progress = $status['progress'] ?? [];

        $this->assertSame(0, $progress['total']);
        $this->assertSame(0, $progress['passed']);
        $this->assertSame(0, $progress['failed']);
        $this->assertSame(0, $progress['invalid']);
        $this->assertSame(0, $progress['skipped']);
        $this->assertSame(0, $progress['running']);
        $this->assertSame(0, $progress['pending']);
        $this->assertSame(0, $progress['remaining']);
    }

    public function test_next_suggests_resume_while_cases_pending_and_battery_report_once_settled(): void
    {
        $service = $this->battery();
        $runId = 'matrix-next-'.Str::lower(Str::random(6));
        $cases = $this->synthesise40Cases();
        $service->initialize($runId, ['preset' => 'release'], $cases);

        // Setup must look real to NextService: provision the worktree dirs.
        $paths = app(\App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['atlas'], 0o755, true);
        @mkdir($paths['rival'], 0o755, true);

        $next = $this->next()->next(['run_id' => $runId]);
        $this->assertSame('battery_paused_pending_cases', $next['phase']);
        $this->assertStringContainsString('resume --run-id='.$runId, (string) $next['next_command']);
        $this->assertSame($cases[0]['id'], $next['observations']['battery_next_case_id']);

        // Mark every case terminal (mix of completed/failed) and re-ask.
        foreach ($cases as $case) {
            $verdict = ((int) hexdec(substr(md5($case['id']), 0, 2)) % 2 === 0)
                ? 'comparable'
                : 'invalid_tests_failed';
            $service->markCaseFinished($runId, (string) $case['id'], $verdict);
        }
        $next2 = $this->next()->next(['run_id' => $runId]);
        $this->assertSame('battery_settled_all_cases_terminal', $next2['phase']);
        $this->assertStringContainsString('battery-report --run-id='.$runId, (string) $next2['next_command']);
    }

    public function test_resume_preserves_completed_cases_and_only_iterates_pending(): void
    {
        $service = $this->battery();
        $runId = 'matrix-resume-'.Str::lower(Str::random(6));
        $cases = $this->synthesise40Cases();
        $service->initialize($runId, ['preset' => 'release'], $cases);

        // First session settles 4 cases.
        $service->markCaseFinished($runId, 'cat-backend_logic-L1', 'comparable');
        $service->markCaseFinished($runId, 'cat-backend_logic-L3', 'comparable');
        $service->markCaseFinished($runId, 'cat-frontend_ui-L5', 'invalid_tests_failed');
        $service->markCaseSkipped($runId, 'cat-architecture-L5', 'operator_aborted');

        // Resume: initialize must keep those four terminal and surface the
        // 36 remaining as pending.
        $afterResume = $service->initialize($runId, ['preset' => 'release'], $cases);
        $this->assertGreaterThanOrEqual(1, $afterResume['resume_count']);
        $byId = [];
        foreach ($afterResume['cases'] as $row) {
            $byId[$row['case_id']] = $row['state'];
        }
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED, $byId['cat-backend_logic-L1']);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED, $byId['cat-backend_logic-L3']);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED, $byId['cat-frontend_ui-L5']);
        $this->assertSame(AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED, $byId['cat-architecture-L5']);
        $this->assertCount(36, $service->pendingCases($afterResume));
    }

    public function test_battery_report_surfaces_planning_execution_difficulty_score_blocks(): void
    {
        $service = $this->battery();
        $runId = 'matrix-report-'.Str::lower(Str::random(6));
        $cases = $this->synthesise40Cases();
        $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);

        // Settle 20 / 40 (alternating completed vs failed) to exercise weighted scoring.
        foreach ($cases as $i => $case) {
            if ($i % 2 === 0) {
                $service->markCaseFinished($runId, (string) $case['id'], 'comparable');
            } else {
                $service->markCaseFinished($runId, (string) $case['id'], 'invalid_tests_failed');
            }
        }
        $service->finalize($runId, [
            'aggregate_verdict' => 'invalid_tests_failed',
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ]);

        $report = $this->reporter()->render(['run_id' => $runId]);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(40, $report['case_count']);
        $this->assertFalse($report['claim_ready']);

        // Three additional score blocks must exist beside weighted_score.
        $this->assertArrayHasKey('planning_score', $report);
        $this->assertArrayHasKey('execution_score', $report);
        $this->assertArrayHasKey('difficulty_score', $report);
        $this->assertSame('planning_weight', $report['planning_score']['weight_key']);
        $this->assertSame('execution_weight', $report['execution_score']['weight_key']);
        $this->assertSame('difficulty_score', $report['difficulty_score']['weight_key']);
        $this->assertSame(40, $report['planning_score']['case_count']);
        $this->assertSame(40, $report['execution_score']['case_count']);

        // 20 / 40 completed → score_percent must be between 0 and 100 and > 0.
        $this->assertGreaterThan(0.0, $report['planning_score']['score_percent']);
        $this->assertLessThanOrEqual(100.0, $report['planning_score']['score_percent']);
        $this->assertGreaterThan(0.0, $report['execution_score']['score_percent']);

        $this->assertFileExists($report['report_path']);
        $md = (string) file_get_contents($report['report_path']);
        $this->assertStringContainsString('planning_score', $md);
        $this->assertStringContainsString('execution_score', $md);
        $this->assertStringContainsString('difficulty_score', $md);
    }

    public function test_dispatcher_routes_resume_status_and_battery_report_against_matrix_battery(): void
    {
        $service = $this->battery();
        $runId = 'matrix-dispatcher-'.Str::lower(Str::random(6));
        $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $this->synthesise40Cases());

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $status = $dispatcher->dispatch('status', ['run_id' => $runId]);
        $this->assertArrayHasKey('progress', $status);
        $this->assertSame(40, $status['progress']['total']);
        $this->assertSame(40, $status['progress']['remaining']);

        $report = $dispatcher->dispatch('battery-report', ['run_id' => $runId]);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(40, $report['case_count']);
    }

    public function test_artisan_status_emits_progress_block_for_matrix_battery(): void
    {
        $service = $this->battery();
        $runId = 'matrix-artisan-'.Str::lower(Str::random(6));
        $service->initialize($runId, ['preset' => 'release'], $this->synthesise40Cases());
        $service->markCaseFinished($runId, 'cat-backend_logic-L1', 'comparable');

        \Illuminate\Support\Facades\Artisan::call('atlas:forge:rivals', [
            'action' => 'status',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('progress', $payload);
        $this->assertSame(40, $payload['progress']['total']);
        $this->assertSame(1, $payload['progress']['passed']);
        $this->assertSame(39, $payload['progress']['remaining']);
    }

    public function test_matrix_runner_never_unlocks_external_rivals_certification(): void
    {
        $service = $this->battery();
        $runId = 'matrix-sealed-'.Str::lower(Str::random(6));
        $cases = $this->synthesise40Cases();
        $service->initialize($runId, [
            'preset' => 'release',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $cases);

        // Even if every single case completes, the matrix runner never
        // promotes external_rivals_certification or sets claim_ready=true
        // for a local_fake-style synthetic run.
        foreach ($cases as $case) {
            $service->markCaseFinished($runId, (string) $case['id'], 'comparable');
        }

        $report = $this->reporter()->render(['run_id' => $runId]);
        $this->assertTrue($report['separated_from_external_rivals_certification']);
        $this->assertFalse($report['external_provider_call']);
    }

    /**
     * Build 40 synthetic cases laid out as 8 categories × 5 difficulty
     * levels with deterministic ids. Mimics the shape Claude 1's corpus
     * adapter will emit; the runner's contract is that every case carries
     * difficulty_level, difficulty_weight, difficulty_score,
     * planning_weight, execution_weight.
     *
     * @return list<array<string,mixed>>
     */
    private function synthesise40Cases(): array
    {
        $categories = [
            'backend_logic',
            'frontend_ui',
            'realistic_bugfix',
            'refactor',
            'test_design',
            'architecture',
            'integration',
            'performance_edge_case',
        ];
        $taskCategoryAlias = [
            'backend_logic' => 'backend',
            'frontend_ui' => 'frontend',
            'realistic_bugfix' => 'bugfix',
            'refactor' => 'refactor',
            'test_design' => 'tests',
            'architecture' => 'architecture',
            'integration' => 'integration',
            'performance_edge_case' => 'performance',
        ];
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $out = [];
        foreach ($categories as $category) {
            foreach ($levels as $level) {
                $weight = AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level);
                $out[] = [
                    'id' => 'cat-'.$category.'-'.$level,
                    'case_source' => 'provider_arena_corpus',
                    'case_set' => 'matrix',
                    'task_category' => $taskCategoryAlias[$category],
                    'category' => $category,
                    'difficulty' => match ($level) {
                        'L1' => 'easy', 'L2' => 'easy', 'L3' => 'medium', 'L4' => 'hard', 'L5' => 'hard',
                        default => 'medium',
                    },
                    'difficulty_level' => $level,
                    'difficulty_weight' => $weight,
                    'difficulty_score' => $weight,
                    'planning_weight' => $weight * 0.4,
                    'execution_weight' => $weight * 0.6,
                ];
            }
        }

        return $out;
    }

    private function battery(): AtlasForgeRivalsBatteryStateService
    {
        return app(AtlasForgeRivalsBatteryStateService::class);
    }

    private function reporter(): AtlasForgeRivalsBatteryReportService
    {
        return app(AtlasForgeRivalsBatteryReportService::class);
    }

    private function statusService(): AtlasForgeRivalsStatusService
    {
        return app(AtlasForgeRivalsStatusService::class);
    }

    private function next(): AtlasForgeRivalsNextService
    {
        return app(AtlasForgeRivalsNextService::class);
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
