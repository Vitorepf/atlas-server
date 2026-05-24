<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReplayVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Evidence Pack + Replay Multi-Case v1.
 *
 * Locks down the battery-level evidence pack + replay verifier contract:
 *
 *   - per-case digest enumerated by the per-run pack (cases[]) with L1-L5
 *     difficulty propagated from the corpus;
 *   - battery aggregate sums category_summary + difficulty_summary;
 *   - missing provider receipt in any case blocks the battery;
 *   - dirty after-run in any case blocks the battery;
 *   - missing test log in any case blocks the battery;
 *   - missing difficulty_level in any case blocks the battery;
 *   - patch diff sha256 mismatch in any case blocks the battery.
 */
final class AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsReplayService $replay;

    private AtlasForgeRivalsEvidencePackVerifierService $verifier;

    private AtlasForgeRivalsBatteryEvidenceService $battery;

    private AtlasForgeRivalsBatteryReplayVerifierService $batteryVerifier;

    private mixed $oldEvidenceFloor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => 0]);
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-battery-mc-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config([
            'atlas_rivals.runs_root' => $this->tmpRoot,
            'atlas_rivals.min_free_bytes_before_provider_evidence' => 0,
        ]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->verifier = new AtlasForgeRivalsEvidencePackVerifierService($this->paths, $this->replay);
        $this->battery = new AtlasForgeRivalsBatteryEvidenceService($this->paths, $this->collect);
        $this->batteryVerifier = new AtlasForgeRivalsBatteryReplayVerifierService(
            $this->paths,
            $this->battery,
            $this->verifier,
        );
    }

    protected function tearDown(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => $this->oldEvidenceFloor]);
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_battery_evidence_aggregates_per_case_difficulty_l5_and_category_summary(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $result = $this->battery->aggregate([
            'run_ids' => $runIds,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $pack = $result['battery_evidence_pack'];
        $this->assertSame('ok', $result['status'], implode('|', $result['blockers'] ?? []));
        $this->assertCount(2, $pack['cases']);
        $this->assertSame(
            ['backend_logic' => 1, 'frontend_ui' => 1],
            $pack['category_summary']['counts'],
        );
        $this->assertSame(2, $pack['difficulty_summary']['counts'][AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3] ?? null);
        $this->assertSame(0, $pack['difficulty_summary']['missing_difficulty_count']);
        $this->assertFalse($pack['aggregate_claim_ready']);
        $this->assertSame('blocked', $pack['external_rivals_certification_status']);
        $this->assertNotEmpty($pack['battery_pack_path']);
        $this->assertSame(64, strlen((string) $pack['battery_pack_sha256']));
    }

    public function test_battery_replay_verifier_passes_on_clean_two_case_battery(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('passed', $result['verification_status'], implode('|', $result['blockers']));
        $this->assertFalse($result['aggregate_claim_ready']);
        $this->assertSame(2, $result['case_count']);
    }

    public function test_missing_provider_receipt_blocks_battery_claim(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runIds[0]);
        // Strip atlas_receipt.json from the FIRST case subdir.
        @unlink($paths['evidence'].'/cases/case-1/atlas_receipt.json');

        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertFalse($result['aggregate_claim_ready']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'missing_provider_receipt'),
            'expected missing_provider_receipt blocker; got: '.implode('|', $result['blockers']),
        );
    }

    public function test_dirty_after_run_blocks_battery_claim(): void
    {
        $runIds = $this->seedTwoCaseBattery(dirtyCase: 1);
        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertFalse($result['aggregate_claim_ready']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'dirty_after_run'),
            'expected dirty_after_run blocker; got: '.implode('|', $result['blockers']),
        );
    }

    public function test_missing_test_log_blocks_battery_claim(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runIds[0]);
        @unlink($paths['evidence'].'/cases/case-2/atlas_test.log');

        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'missing_test_log'),
            'expected missing_test_log blocker; got: '.implode('|', $result['blockers']),
        );
    }

    public function test_missing_difficulty_blocks_battery_claim(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runIds[0]);
        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        // Drop the difficulty fields from the first case entry so the corpus
        // cannot resolve a difficulty_level — the verifier must block.
        unset($manifest['cases'][0]['difficulty'], $manifest['cases'][0]['difficulty_level']);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'missing_difficulty_level')
                || $this->blockersContain($result['blockers'], 'battery_missing_difficulty'),
            'expected difficulty blocker; got: '.implode('|', $result['blockers']),
        );
    }

    public function test_patch_diff_hash_mismatch_blocks_battery_claim(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runIds[0]);

        // First aggregate to lock in sha256 in the battery pack…
        $this->battery->aggregate([
            'run_ids' => $runIds,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        // …then tamper with the atlas patch in case-1.
        file_put_contents($paths['evidence'].'/cases/case-1/atlas_patch.diff', "--- tampered patch ---\n");

        $result = $this->batteryVerifier->verify([
            'run_ids' => $runIds,
            'mode' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertNotSame('passed', $result['verification_status']);
        $this->assertTrue(
            $this->blockersContain($result['blockers'], 'patch_diff_hash_mismatch')
                || $this->blockersContain($result['blockers'], 'hash_mismatch'),
            'expected hash mismatch blocker; got: '.implode('|', $result['blockers']),
        );
    }

    public function test_cli_battery_evidence_writes_aggregate_pack_on_disk(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'battery-evidence',
            '--run-ids' => implode(',', $runIds),
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);

        $paths = $this->paths->paths($runIds[0]);
        $this->assertFileExists($paths['evidence'].'/battery_evidence_pack.json');
        $pack = json_decode((string) file_get_contents($paths['evidence'].'/battery_evidence_pack.json'), true);
        $this->assertSame(AtlasForgeRivalsBatteryEvidenceService::SCHEMA_VERSION, $pack['schema_version']);
        $this->assertCount(2, $pack['cases']);
        $this->assertSame('blocked', $pack['external_rivals_certification_status']);
    }

    public function test_cli_battery_verify_strict_returns_failure_on_missing_difficulty(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $paths = $this->paths->paths($runIds[0]);
        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        unset($manifest['cases'][0]['difficulty'], $manifest['cases'][0]['difficulty_level']);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'battery-verify-evidence',
            '--run-ids' => implode(',', $runIds),
            '--verify-mode' => 'replay',
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
            '--strict' => true,
        ])->run();
        $this->assertSame(1, $exit);
    }

    public function test_aggregate_claim_ready_remains_false_even_when_every_case_passes(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $result = $this->battery->aggregate([
            'run_ids' => $runIds,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['battery_evidence_pack']['aggregate_claim_ready']);
    }

    public function test_external_rivals_certification_remains_blocked_in_battery_pack(): void
    {
        $runIds = $this->seedTwoCaseBattery();
        $result = $this->battery->aggregate([
            'run_ids' => $runIds,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $this->assertSame('blocked', $result['battery_evidence_pack']['external_rivals_certification_status']);
        $this->assertTrue($result['battery_evidence_pack']['separated_from_external_rivals_certification']);
    }

    public function test_battery_evidence_actions_are_registered_in_cli(): void
    {
        $this->assertContains('battery-evidence', AtlasForgeRivalsCommand::ACTIONS);
        $this->assertContains('battery-verify-evidence', AtlasForgeRivalsCommand::ACTIONS);
    }

    // --- helpers ---

    /**
     * Seed two single-run batteries (run_id_a contains 2 cases, run_id_b is
     * a separate single-case run) and return the run_ids in order.
     *
     * @return list<string>
     */
    private function seedTwoCaseBattery(?int $dirtyCase = null): array
    {
        $runId = 'battery-multi-'.bin2hex(random_bytes(4));
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        @mkdir($paths['evidence'].'/cases/case-1', 0o755, true);
        @mkdir($paths['evidence'].'/cases/case-2', 0o755, true);

        $caseEntries = [
            $this->buildCaseEntry(
                'case-1',
                'backend_logic',
                AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_MEDIUM,
                'comparable',
                $paths,
                'case-1',
                $dirtyCase === 1,
            ),
            $this->buildCaseEntry(
                'case-2',
                'frontend_ui',
                AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_MEDIUM,
                'comparable',
                $paths,
                'case-2',
                $dirtyCase === 2,
            ),
        ];

        // Top-level legacy artifacts (worst-of aggregate / fallback).
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($this->makeReceipt('atlas')));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($this->makeReceipt('rival')));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => $dirtyCase !== null,
            'workspace_blockers' => $dirtyCase !== null ? ['out_of_scope_change:storage/foo.txt'] : [],
            'case_count' => 2,
        ]));
        file_put_contents($paths['evidence'].'/atlas_patch.diff', "--- atlas top-level patch ---\n");
        file_put_contents($paths['evidence'].'/rival_patch.diff', "--- rival top-level patch ---\n");
        file_put_contents($paths['evidence'].'/atlas_test.log', "(50 tests, 120 assertions)\n");
        file_put_contents($paths['evidence'].'/rival_test.log', "(50 tests, 120 assertions)\n");

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'multi_case_aggregate',
            'case_source' => 'provider_arena_corpus',
            'case_set' => 'quick',
            'task_category' => 'backend_logic',
            'case_count' => 2,
            'is_multi_case' => true,
            'cases' => array_map(static fn (array $c): array => $c['perCase'], $caseEntries),
            'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:30:00+00:00',
            'verdict' => 'comparable',
            'score' => null,
            'claim_ready' => false,
            'atlas_receipt_hash' => hash('sha256', 'atlas'),
            'rival_receipt_hash' => hash('sha256', 'rival'),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => $dirtyCase !== null,
            'workspace_blockers' => $dirtyCase !== null ? ['out_of_scope_change:storage/foo.txt'] : [],
            'workspace_changes_after_run' => ['atlas' => [], 'rival' => []],
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents(
            $paths['events_jsonl'],
            json_encode(['kind' => 'run_started']).PHP_EOL.
            json_encode(['kind' => 'heartbeat']).PHP_EOL.
            json_encode(['kind' => 'final_report']).PHP_EOL,
        );
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic_multi_case']));

        return [$runId];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCaseEntry(string $caseId, string $taskCategory, string $difficulty, string $verdict, array $paths, string $caseSubdir, bool $dirty): array
    {
        $caseDir = $paths['evidence'].'/cases/'.$caseSubdir;
        @mkdir($caseDir, 0o755, true);
        $atlasReceipt = $this->makeReceipt('atlas');
        $rivalReceipt = $this->makeReceipt('rival');
        file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($caseDir.'/atlas_patch.diff', "--- atlas patch for {$caseId} ---\n");
        file_put_contents($caseDir.'/rival_patch.diff', "--- rival patch for {$caseId} ---\n");
        file_put_contents($caseDir.'/atlas_test.log', "(case={$caseId} 50 tests, 120 assertions)\n");
        file_put_contents($caseDir.'/rival_test.log', "(case={$caseId} 50 tests, 120 assertions)\n");
        file_put_contents($caseDir.'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1-'.$caseId, 'rival' => 'h1-'.$caseId],
            'after' => ['atlas' => 'h2-'.$caseId, 'rival' => 'h2-'.$caseId],
            'dirty_after_run' => $dirty,
            'workspace_blockers' => $dirty ? ['out_of_scope_change:storage/foo.txt'] : [],
        ]));

        $atlasPatchSha = hash_file('sha256', $caseDir.'/atlas_patch.diff') ?: null;
        $rivalPatchSha = hash_file('sha256', $caseDir.'/rival_patch.diff') ?: null;
        $atlasTestSha = hash_file('sha256', $caseDir.'/atlas_test.log') ?: null;
        $rivalTestSha = hash_file('sha256', $caseDir.'/rival_test.log') ?: null;
        $atlasReceipt['patch_diff_path'] = $caseDir.'/atlas_patch.diff';
        $atlasReceipt['patch_diff_hash'] = $atlasPatchSha;
        $atlasReceipt['patch_diff_bytes'] = (int) (filesize($caseDir.'/atlas_patch.diff') ?: 0);
        $atlasReceipt['test_log_path'] = $caseDir.'/atlas_test.log';
        $atlasReceipt['test_log_hash'] = $atlasTestSha;
        $rivalReceipt['patch_diff_path'] = $caseDir.'/rival_patch.diff';
        $rivalReceipt['patch_diff_hash'] = $rivalPatchSha;
        $rivalReceipt['patch_diff_bytes'] = (int) (filesize($caseDir.'/rival_patch.diff') ?: 0);
        $rivalReceipt['test_log_path'] = $caseDir.'/rival_test.log';
        $rivalReceipt['test_log_hash'] = $rivalTestSha;
        file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($rivalReceipt));

        $perCase = [
            'case_id' => $caseId,
            'case_index' => 0,
            'case_source' => 'provider_arena_corpus',
            'task_category' => $taskCategory,
            'case_set' => 'quick',
            'difficulty' => $difficulty,
            'difficulty_level' => AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($difficulty),
            'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($difficulty),
            ),
            'verdict' => $verdict,
            'evidence_subdir' => 'cases/'.$caseSubdir,
            'workspace_hash_before' => ['atlas' => 'h1-'.$caseId, 'rival' => 'h1-'.$caseId],
            'workspace_hash_after' => ['atlas' => 'h2-'.$caseId, 'rival' => 'h2-'.$caseId],
            'workspace_blockers' => $dirty ? ['out_of_scope_change:storage/foo.txt'] : [],
            'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
            'atlas_arm' => [
                'exit_code' => 0,
                'test_exit_code' => 0,
                'killed' => false,
                'timeout_reason' => null,
                'patch_diff_bytes' => $atlasReceipt['patch_diff_bytes'],
                'patch_diff_hash' => $atlasPatchSha,
                'patch_diff_path' => $atlasReceipt['patch_diff_path'],
                'test_log_path' => $atlasReceipt['test_log_path'],
                'changed_files' => ['app/A.php'],
                'out_of_scope_files' => [],
                'bytecode_artifacts' => [],
            ],
            'rival_arm' => [
                'exit_code' => 0,
                'test_exit_code' => 0,
                'killed' => false,
                'timeout_reason' => null,
                'patch_diff_bytes' => $rivalReceipt['patch_diff_bytes'],
                'patch_diff_hash' => $rivalPatchSha,
                'patch_diff_path' => $rivalReceipt['patch_diff_path'],
                'test_log_path' => $rivalReceipt['test_log_path'],
                'changed_files' => ['app/R.php'],
                'out_of_scope_files' => [],
                'bytecode_artifacts' => [],
            ],
        ];

        return ['perCase' => $perCase];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeReceipt(string $arm): array
    {
        return [
            'arm' => $arm,
            'mode' => 'fair',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm.'-cmd'),
            'prompt_hash' => hash('sha256', $arm.'-prompt'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'-so'),
            'stderr_hash' => hash('sha256', $arm.'-se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => 0.01,
            'tokens_used' => 100,
            'worktree' => '/tmp/work',
            'case_id' => 'multi-case',
            'changed_files' => ['app/A.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'-pd'),
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'-tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
            'fake' => false,
        ];
    }

    /**
     * @param  list<string>  $blockers
     */
    private function blockersContain(array $blockers, string $needle): bool
    {
        foreach ($blockers as $b) {
            if (str_contains((string) $b, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
