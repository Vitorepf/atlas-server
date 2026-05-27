<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsNextService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsStatusService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
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
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
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

    public function test_next_blocks_historical_missing_run_with_external_evidence_restore_guidance(): void
    {
        $runId = 'battery-20260516-145210-yd5pil';

        $next = $this->next()->next(['run_id' => $runId]);

        $this->assertSame('blocked', $next['status']);
        $this->assertSame('external_evidence_missing', $next['phase']);
        $this->assertContains('run_not_found:'.$runId, $next['blockers']);
        $this->assertContains('external_evidence_artifact_missing', $next['blockers']);
        $this->assertTrue($next['observations']['external_evidence_required_before_claim']);
        $this->assertFalse($next['observations']['score_or_claim_allowed']);
        $this->assertFalse($next['external_provider_call']);
        $this->assertFalse($next['provider_tokens_spent']);
        $this->assertTrue($next['advisory_only']);
        $this->assertFalse($next['should_update_provider_topology']);
        $this->assertSame('none', $next['routing_effect']);
        $this->assertContains('restore_run_evidence_directory', array_column($next['actions'], 'kind'));
        $this->assertContains('ingest_external_deepswe_result', array_column($next['actions'], 'kind'));
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

        Artisan::call('atlas:forge:rivals', [
            'action' => 'status',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('progress', $payload);
        $this->assertSame(40, $payload['progress']['total']);
        $this->assertSame(1, $payload['progress']['passed']);
        $this->assertSame(39, $payload['progress']['remaining']);
    }

    public function test_artisan_runs_inventory_lists_local_evidence_without_provider_call(): void
    {
        $runId = 'inventory-ready-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['scorecard_v2_json'], json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['report_md'], "# test\n");

        Artisan::call('atlas:forge:rivals', [
            'action' => 'runs',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.run_inventory.v1', $payload['schema_version']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertFalse($payload['score_or_claim_allowed']);

        $row = $payload['runs_preview'][0] ?? [];
        $this->assertSame($runId, $row['run_id']);
        $this->assertSame('report_materialized', $row['phase']);
        $this->assertTrue($row['manifest_present']);
        $this->assertTrue($row['evidence_pack_present']);
        $this->assertTrue($row['scorecard_present']);
        $this->assertTrue($row['report_present']);
        $this->assertTrue($row['replay_candidate']);
        $this->assertFalse($row['claim_candidate']);
        $this->assertStringContainsString('replay --run-id='.$runId, $row['next_command']);
        $this->assertSame([], $payload['missing_requested_runs']);
    }

    public function test_artisan_runs_inventory_marks_missing_historical_evidence_as_external_missing(): void
    {
        $runId = 'battery-20260516-145210-yd5pil';

        Artisan::call('atlas:forge:rivals', [
            'action' => 'runs',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['external_claim_allowed']);

        $missing = $payload['missing_requested_runs'][0] ?? [];
        $this->assertSame($runId, $missing['run_id']);
        $this->assertSame('external_evidence_missing', $missing['phase']);
        $this->assertContains('run_not_found:'.$runId, $missing['blockers']);
        $this->assertContains('external_evidence_artifact_missing', $missing['blockers']);
        $this->assertTrue($missing['restore_required']);
        $this->assertFalse($missing['score_or_claim_allowed']);
        $this->assertStringContainsString('next --run-id='.$runId, $missing['next_command']);
    }

    public function test_artisan_evidence_bundle_manifest_exports_hash_pinned_file_list_without_claim(): void
    {
        $runId = 'bundle-ready-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started'], JSON_THROW_ON_ERROR)."\n");
        file_put_contents($paths['evidence'].'/atlas_receipt.json', json_encode(['provider' => 'local_fake'], JSON_THROW_ON_ERROR));
        $receiptSha = hash_file('sha256', $paths['evidence'].'/atlas_receipt.json');
        file_put_contents($paths['evidence'].'/artifact_index.json', json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode([
            'schema_version' => 'test',
            'run_id' => $runId,
            'artifacts' => [
                'atlas_receipt' => [
                    'path' => $paths['evidence'].'/atlas_receipt.json',
                    'present' => true,
                    'sha256' => $receiptSha,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $outputPath = $this->rootOverride.'/portable-'.$runId.'.json';
        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.evidence_bundle_manifest.v1', $payload['schema_version']);
        $this->assertTrue($payload['bundle_ready']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertFileExists($outputPath);

        $relativePaths = array_column($payload['files'], 'relative_path');
        $this->assertContains('events.jsonl', $relativePaths);
        $this->assertContains('evidence/manifest.json', $relativePaths);
        $this->assertContains('evidence/evidence_pack.json', $relativePaths);
        $this->assertContains('evidence/artifact_index.json', $relativePaths);
        $this->assertContains('evidence/atlas_receipt.json', $relativePaths);
        $this->assertStringContainsString('tar -C', $payload['archive_command']);
        $this->assertStringContainsString('replay --run-id='.$runId, $payload['next_command']);
    }

    public function test_artisan_evidence_bundle_verify_accepts_intact_manifest_without_claim(): void
    {
        $runId = 'bundle-verify-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started'], JSON_THROW_ON_ERROR)."\n");
        file_put_contents($paths['evidence'].'/artifact_index.json', json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode([
            'schema_version' => 'test',
            'run_id' => $runId,
            'artifacts' => [],
        ], JSON_THROW_ON_ERROR));

        $outputPath = $this->rootOverride.'/portable-'.$runId.'.json';
        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle-verify',
            '--input' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.evidence_bundle_verification.v1', $payload['schema_version']);
        $this->assertTrue($payload['bundle_verified']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertStringContainsString('replay --run-id='.$runId, $payload['next_command']);
    }

    public function test_artisan_evidence_bundle_verify_accepts_restored_run_dir_override(): void
    {
        $runId = 'bundle-relocated-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started'], JSON_THROW_ON_ERROR)."\n");
        file_put_contents($paths['evidence'].'/artifact_index.json', json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode([
            'schema_version' => 'test',
            'run_id' => $runId,
            'artifacts' => [],
        ], JSON_THROW_ON_ERROR));

        $outputPath = $this->rootOverride.'/portable-'.$runId.'.json';
        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--output-path' => $outputPath,
            '--json' => true,
        ]);

        $restoredRoot = $this->rootOverride.'-restored';
        $restoredRunDir = $restoredRoot.'/'.$runId;
        $this->copyDir($paths['base'], $restoredRunDir);
        $this->wipeDir($paths['base']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle-verify',
            '--input' => $outputPath,
            '--bundle-run-dir' => $restoredRunDir,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['bundle_verified']);
        $this->assertTrue($payload['run_dir_overridden']);
        $this->assertSame($restoredRunDir, $payload['run_dir']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['score_or_claim_allowed']);

        $this->wipeDir($restoredRoot);
    }

    public function test_artisan_evidence_bundle_verify_blocks_tampered_file_hash(): void
    {
        $runId = 'bundle-tamper-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started'], JSON_THROW_ON_ERROR)."\n");
        file_put_contents($paths['evidence'].'/artifact_index.json', json_encode(['schema_version' => 'test'], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode([
            'schema_version' => 'test',
            'run_id' => $runId,
            'artifacts' => [],
        ], JSON_THROW_ON_ERROR));

        $outputPath = $paths['evidence'].'/portable_bundle_manifest.json';
        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--output-path' => $outputPath,
            '--json' => true,
        ]);
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'tampered'], JSON_THROW_ON_ERROR)."\n");

        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle-verify',
            '--input' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['bundle_verified']);
        $this->assertContains('bundle_verify_hash_mismatch:events.jsonl', $payload['blockers']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_artisan_evidence_bundle_manifest_blocks_when_required_bundle_files_are_missing(): void
    {
        $runId = 'bundle-blocked-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        file_put_contents($paths['evidence'].'/evidence_pack.json', json_encode(['artifacts' => []], JSON_THROW_ON_ERROR));

        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['bundle_ready']);
        $this->assertContains('bundle_required_file_missing:events.jsonl', $payload['blockers']);
        $this->assertContains('bundle_required_file_missing:evidence/artifact_index.json', $payload['blockers']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_artisan_trusted_signal_gate_allows_ledger_feed_only_after_evidence_replay_and_metadata_are_ready(): void
    {
        [$runId, $bundlePath] = $this->writeTrustedSignalRun('trusted-ready', mode: 'fair');

        Artisan::call('atlas:forge:rivals', [
            'action' => 'trusted-signal',
            '--run-id' => $runId,
            '--input' => $bundlePath,
            '--task-category' => 'backend',
            '--role' => 'builder',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.trusted_signal_gate.v1', $payload['schema_version']);
        $this->assertTrue($payload['trusted_signal_ready']);
        $this->assertTrue($payload['can_feed_provider_performance_ledger']);
        $this->assertTrue($payload['can_feed_atlas_decide_advisory_signal']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertSame('backend', $payload['task_category']);
        $this->assertSame('builder', $payload['role']);
        $this->assertTrue($payload['replay_passes']);
        $this->assertTrue($payload['scorecard_replay_passes']);
        $this->assertTrue($payload['bundle_verified']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['evidence_pack_hash']);
        $this->assertStringContainsString('ledger-record --run-id='.$runId, $payload['next_command']);
    }

    public function test_artisan_trusted_signal_gate_blocks_local_fake_from_provider_performance_signal(): void
    {
        [$runId, $bundlePath] = $this->writeTrustedSignalRun('trusted-fake', mode: 'local_fake');

        Artisan::call('atlas:forge:rivals', [
            'action' => 'trusted-signal',
            '--run-id' => $runId,
            '--input' => $bundlePath,
            '--task-category' => 'backend',
            '--role' => 'builder',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['trusted_signal_ready']);
        $this->assertFalse($payload['can_feed_provider_performance_ledger']);
        $this->assertFalse($payload['can_feed_atlas_decide_advisory_signal']);
        $this->assertContains('diagnostic_or_local_fake_run_not_trusted_signal', $payload['blockers']);
        $this->assertFalse($payload['score_or_claim_allowed']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_external_evidence_lifecycle_survives_restore_then_feeds_ledger_and_decide_signal(): void
    {
        [$runId, $bundlePath] = $this->writeTrustedSignalRun(
            'external-lifecycle',
            mode: 'fair',
            atlasProvider: 'anthropic_claude',
            atlasModel: 'claude_sonnet',
            rivalProvider: 'openai_codex',
            rivalModel: 'gpt-5.5',
        );
        $originalPaths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        $portableManifest = $this->rootOverride.'/portable-'.$runId.'.json';
        copy($bundlePath, $portableManifest);

        $restoredRoot = $this->rootOverride.'-restored';
        $restoredRunDir = $restoredRoot.'/'.$runId;
        $this->copyDir($originalPaths['base'], $restoredRunDir);
        $this->wipeDir($originalPaths['base']);
        config()->set('atlas_rivals.runs_root', $restoredRoot);
        config()->set('atlas_rivals.ledger_root', $restoredRoot.'/ledger');

        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle-verify',
            '--input' => $portableManifest,
            '--bundle-run-dir' => $restoredRunDir,
            '--json' => true,
        ]);
        $bundle = json_decode(Artisan::output(), true);
        $this->assertIsArray($bundle);
        $this->assertSame('ok', $bundle['status']);
        $this->assertTrue($bundle['bundle_verified']);
        $this->assertTrue($bundle['run_dir_overridden']);
        $this->assertFalse($bundle['score_or_claim_allowed']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'replay',
            '--run-id' => $runId,
            '--json' => true,
            '--strict' => true,
        ]);
        $replay = json_decode(Artisan::output(), true);
        $this->assertIsArray($replay);
        $this->assertSame('ok', $replay['status']);
        $this->assertTrue($replay['replay_passes']);
        $this->assertSame([], $replay['mismatches']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'trusted-signal',
            '--run-id' => $runId,
            '--input' => $portableManifest,
            '--bundle-run-dir' => $restoredRunDir,
            '--task-category' => 'backend',
            '--role' => 'builder',
            '--json' => true,
        ]);
        $trusted = json_decode(Artisan::output(), true);
        $this->assertIsArray($trusted);
        $this->assertSame('ok', $trusted['status']);
        $this->assertTrue($trusted['trusted_signal_ready']);
        $this->assertTrue($trusted['can_feed_provider_performance_ledger']);
        $this->assertTrue($trusted['can_feed_atlas_decide_advisory_signal']);
        $this->assertFalse($trusted['claim_ready']);
        $this->assertSame('none', $trusted['routing_effect']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'ledger-record',
            '--run-id' => $runId,
            '--task-category' => 'backend',
            '--role' => 'builder',
            '--json' => true,
        ]);
        $ledgerRecord = json_decode(Artisan::output(), true);
        $this->assertIsArray($ledgerRecord);
        $this->assertSame('ok', $ledgerRecord['status']);
        $this->assertCount(2, $ledgerRecord['entries_recorded']);
        $this->assertSame('anthropic_claude', $ledgerRecord['entries_recorded'][0]['provider']);
        $this->assertSame('openai_codex', $ledgerRecord['entries_recorded'][1]['provider']);
        $this->assertSame(1000, $ledgerRecord['entries_recorded'][0]['tokens_used']);
        $this->assertSame(0.01, $ledgerRecord['entries_recorded'][0]['cost_estimate']);
        $this->assertTrue($ledgerRecord['entries_recorded'][0]['tests_passed']);
        $this->assertFalse($ledgerRecord['external_provider_call']);
        $this->assertFalse($ledgerRecord['provider_tokens_spent']);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'decide-signal',
            '--task-category' => 'backend',
            '--role' => 'builder',
            '--json' => true,
        ]);
        $decide = json_decode(Artisan::output(), true);
        $this->assertIsArray($decide);
        $this->assertSame('ok', $decide['status']);
        $this->assertSame('anthropic_claude', $decide['top_measured_provider']);
        $this->assertSame('claude_sonnet', $decide['top_measured_model']);
        $this->assertSame('low', $decide['confidence']);
        $this->assertTrue($decide['advisory_only']);
        $this->assertFalse($decide['should_update_provider_topology']);
        $this->assertTrue($decide['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $decide['owner_of_model_routing']);
        $this->assertSame('none', $decide['routing_effect']);

        $this->wipeDir($restoredRoot);
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

    /**
     * @return array{0:string,1:string}
     */
    private function writeTrustedSignalRun(
        string $prefix,
        string $mode,
        string $atlasProvider = 'claude',
        string $atlasModel = 'claude_sonnet',
        string $rivalProvider = 'claude',
        string $rivalModel = 'claude_sonnet',
    ): array {
        $runId = $prefix.'-'.Str::lower(Str::random(6));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'mode' => $mode,
            'preset' => 'quick',
            'task_category' => 'backend',
            'role' => 'builder',
            'case_id' => 'trusted-signal-case',
            'task_id' => 'trusted-signal-case',
            'case_source' => 'test',
            'difficulty_level' => 'L3',
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'verdict' => 'comparable',
            'claim_ready' => false,
            'external_provider_call' => $mode !== 'local_fake',
            'provider_tokens_spent' => $mode !== 'local_fake',
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], $this->jsonEncode(['kind' => 'run_started'])."\n");
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode([
            'arm' => 'atlas',
            'provider' => $atlasProvider,
            'model' => $atlasModel,
            'exit_code' => 0,
            'test_exit_code' => 0,
            'tokens_used' => 1000,
            'token_cost' => 0.01,
            'changed_files' => ['app/Foo.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
        ]));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode([
            'arm' => 'rival',
            'provider' => $rivalProvider,
            'model' => $rivalModel,
            'exit_code' => 0,
            'test_exit_code' => 0,
            'tokens_used' => 1000,
            'token_cost' => 0.01,
            'changed_files' => ['app/Foo.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
        ]));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));
        $scorecard = [
            'schema_version' => 'atlas.forge.rivals.adjudication.v1',
            'run_id' => $runId,
            'winner' => 'atlas',
            'atlas_score' => 84.0,
            'rival_score' => 64.0,
            'hard_failures' => [],
            'replay_passes' => true,
            'claim_ready' => false,
            'human_review_required' => false,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['scorecard_json'], $this->jsonEncode($scorecard));

        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'scorecard' => $paths['scorecard_json'],
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => true,
                'bytes' => filesize($path) ?: 0,
                'sha256' => hash_file('sha256', $path),
            ];
        }
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode([
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => $runId,
            'mode_for_evidence' => $mode,
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => [],
            'verdict' => 'comparable',
            'claim_ready' => false,
        ]));
        file_put_contents($paths['evidence'].'/artifact_index.json', $this->jsonEncode([
            'schema_version' => 'atlas.forge.rivals.artifact_index.v1',
            'run_id' => $runId,
            'artifacts' => $artifacts,
        ]));

        $bundlePath = $paths['evidence'].'/portable_bundle_manifest.json';
        Artisan::call('atlas:forge:rivals', [
            'action' => 'evidence-bundle',
            '--run-id' => $runId,
            '--output-path' => $bundlePath,
            '--json' => true,
        ]);

        return [$runId, $bundlePath];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    private function copyDir(string $source, string $destination): void
    {
        if (! is_dir($destination)) {
            @mkdir($destination, 0o755, true);
        }

        $items = scandir($source) ?: [];
        foreach ($items as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.DIRECTORY_SEPARATOR.$entry;
            $to = $destination.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }
}
