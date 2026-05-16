<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1.
 *
 * Locks the new sanity gates and 5-level `confidence_level` ladder
 * (invalid/low/medium/high/release_trusted) on top of the existing
 * fairness layer. The release_trusted level is intentionally unreachable
 * from a single run — that case lives in the battery report test suite.
 *
 * Invariants exercised here:
 *   - Provider driver failure (empty output) ⇒ invalid_provider_run, NOT
 *     a low quality score.
 *   - Fixture corruption (manifest verdict / receipt fixture_error /
 *     case_id mismatch / fixture_blockers) ⇒ invalid_fixture.
 *   - Missing evidence / replay drift ⇒ confidence_level = invalid.
 *   - Extreme score margin without intact evidence ⇒ needs_triage AND
 *     confidence_level = invalid.
 *   - Clean fair-mode win with intact evidence ⇒ confidence_level = high.
 *   - release_trusted is NEVER assigned from a single-run scorecard.
 *
 * Read-only: no provider invocation, no token spend.
 */
final class AtlasForgeRivalsAdjudicatorScoringSanityV1Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsAdjudicatorService $adjudicator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-sanity-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->adjudicator = new AtlasForgeRivalsAdjudicatorService($this->paths, $replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_extreme_score_under_local_fake_is_invalid_not_a_high_confidence_win(): void
    {
        $runId = $this->newRunId('extreme-fake');
        $bigRivalFiles = array_map(static fn (int $i): string => 'src/sprawl_'.$i.'.php', range(1, 80));
        $this->seedRun(
            $runId,
            mode: 'local_fake',
            atlas: [
                'patch_diff_bytes' => 1500,
                'changed_files' => ['tests/Feature/A.php', 'app/A.php'],
                'test_log_tail' => '(900 tests, 5000 assertions)',
                'stdout_bytes' => 2000,
            ],
            rival: [
                'patch_diff_bytes' => 250000,
                'changed_files' => $bigRivalFiles,
                'test_log_tail' => '(1 test, 1 assertion)',
                'stdout_bytes' => 50000,
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
            $fairness['confidence_level'],
        );
        $this->assertContains($fairness['validity_class'], [
            AtlasForgeRivalsAdjudicatorService::VALIDITY_NEEDS_TRIAGE,
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_LOCAL_FAKE,
        ]);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_provider_driver_silent_failure_is_invalid_provider_run_not_a_free_win(): void
    {
        $runId = $this->newRunId('driver-silent');
        // Rival "ran" but the driver produced nothing — no stdout, no
        // patch, no test log path, and exit code is whatever (we use 0 to
        // make sure the empty-output detector fires independent of exit).
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 2000, 'stdout_bytes' => 4000],
            rival: [
                'exit_code' => 0,
                'test_exit_code' => 0,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
                'patch_diff_bytes' => 0,
                'test_log_path' => '',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_PROVIDER_RUN,
            $fairness['validity_class'],
        );
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
            $fairness['confidence_level'],
        );
        $this->assertFalse($fairness['sanity_gates']['provider_run_clean']);
        $this->assertNull($scorecard['winner']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_fixture_corruption_via_manifest_verdict_is_invalid_fixture(): void
    {
        $runId = $this->newRunId('fixture-broken');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['patch_diff_bytes' => 2000],
            rival: ['patch_diff_bytes' => 2000],
            manifestOverrides: ['verdict' => 'invalid_fixture_seed_missing'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_FIXTURE,
            $fairness['validity_class'],
        );
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
            $fairness['confidence_level'],
        );
        $this->assertFalse($fairness['sanity_gates']['fixture_clean']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_fixture_corruption_via_receipt_fixture_error_is_invalid_fixture(): void
    {
        $runId = $this->newRunId('fixture-receipt-error');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['patch_diff_bytes' => 2000, 'fixture_error' => true],
            rival: ['patch_diff_bytes' => 2000],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_FIXTURE,
            $fairness['validity_class'],
        );
        $this->assertFalse($fairness['sanity_gates']['fixture_clean']);
    }

    public function test_replay_missing_invalidates_claim_and_drops_confidence_level_to_invalid(): void
    {
        $runId = $this->newRunId('replay-missing');
        $this->seedRun($runId, mode: 'fair', atlas: [], rival: []);
        // Corrupt the manifest after seeding so replay no longer passes.
        $paths = $this->paths->paths($runId);
        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        $manifest['workspace_hash_after']['atlas'] = 'tampered';
        file_put_contents($paths['manifest_json'], json_encode($manifest));

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_REPLAY_DRIFT,
            $fairness['validity_class'],
        );
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
            $fairness['confidence_level'],
        );
        $this->assertFalse($fairness['sanity_gates']['replay_verified']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_high_confidence_requires_fair_mode_replay_evidence_and_clear_margin(): void
    {
        $runId = $this->newRunId('fair-clean-win');
        // Atlas produces a focused diff with strong test signal; rival
        // produces a sprawling diff with poor tests. Margin clears the
        // tie_threshold*2 floor and mode is `fair` with intact evidence.
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: [
                'patch_diff_bytes' => 1500,
                'changed_files' => ['tests/Feature/A.php', 'app/A.php'],
                'test_log_tail' => '(300 tests, 800 assertions)',
                'stdout_bytes' => 2000,
            ],
            rival: [
                'patch_diff_bytes' => 80000,
                'changed_files' => array_map(static fn (int $i): string => 'src/wide_'.$i.'.php', range(1, 9)),
                'test_log_tail' => '(4 tests, 8 assertions)',
                'stdout_bytes' => 8000,
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(AtlasForgeRivalsAdjudicatorService::VALIDITY_VALID, $fairness['validity_class']);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_HIGH,
            $fairness['confidence_level'],
        );
        $this->assertTrue($fairness['sanity_gates']['provider_run_clean']);
        $this->assertTrue($fairness['sanity_gates']['fixture_clean']);
        $this->assertTrue($fairness['sanity_gates']['replay_verified']);
        $this->assertTrue($fairness['sanity_gates']['evidence_complete']);
        $this->assertTrue($fairness['sanity_gates']['both_sides_produced_artifacts']);
        $this->assertTrue($fairness['sanity_gates']['human_intervention_clean']);
        $this->assertContains($scorecard['winner'], [
            AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
        ]);
        $this->assertTrue($fairness['claim_ready_recommended']);
        $this->assertTrue($scorecard['claim_ready']);
    }

    public function test_release_trusted_is_never_assigned_from_a_single_run(): void
    {
        $runId = $this->newRunId('single-run-no-release-trusted');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: [
                'patch_diff_bytes' => 1500,
                'changed_files' => ['tests/Feature/A.php', 'app/A.php'],
                'test_log_tail' => '(300 tests, 800 assertions)',
            ],
            rival: [
                'patch_diff_bytes' => 90000,
                'changed_files' => array_map(static fn (int $i): string => 'src/wide_'.$i.'.php', range(1, 10)),
                'test_log_tail' => '(2 tests, 4 assertions)',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];

        $this->assertNotSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
            $scorecard['fairness']['confidence_level'],
            'release_trusted is a battery-wide promotion, never reachable from a single-case scorecard.',
        );
        $this->assertContains($scorecard['fairness']['confidence_level'], [
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_HIGH,
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_MEDIUM,
        ]);
    }

    public function test_confidence_levels_constant_is_canonical_five_level_ladder(): void
    {
        $this->assertSame(
            ['invalid', 'low', 'medium', 'high', 'release_trusted'],
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVELS,
        );
    }

    // ---------- fixtures ----------

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     * @param  array<string,mixed>  $manifestOverrides
     */
    private function seedRun(string $runId, string $mode, array $atlas, array $rival, array $manifestOverrides = []): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $atlasReceipt = $this->baseReceipt('atlas', $mode, $atlas);
        $rivalReceipt = $this->baseReceipt('rival', $mode, $rival);
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));
        file_put_contents($paths['evidence'].'/atlas_patch.diff', '--- atlas ---');
        file_put_contents($paths['evidence'].'/rival_patch.diff', '--- rival ---');
        file_put_contents($paths['evidence'].'/atlas_test.log', '(50 tests, 120 assertions)');
        file_put_contents($paths['evidence'].'/rival_test.log', '(50 tests, 120 assertions)');

        $manifest = array_replace([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'verdict' => 'comparable',
            'claim_ready' => true,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => $mode !== 'local_fake',
            'provider_tokens_spent' => $mode !== 'local_fake',
            'separated_from_external_rivals_certification' => true,
        ], $manifestOverrides);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'sanity_v1_test']));

        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'atlas_patch' => $paths['evidence'].'/atlas_patch.diff',
            'rival_patch' => $paths['evidence'].'/rival_patch.diff',
            'atlas_test_log' => $paths['evidence'].'/atlas_test.log',
            'rival_test_log' => $paths['evidence'].'/rival_test.log',
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode([
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            'collected_at' => date('c'),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => [],
            'verdict' => 'comparable',
            'claim_ready' => true,
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseReceipt(string $arm, string $mode, array $overrides): array
    {
        return array_replace([
            'arm' => $arm,
            'mode' => $mode,
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm),
            'prompt_hash' => hash('sha256', $arm.'p'),
            'started_at' => '2026-05-16T12:00:00+00:00',
            'finished_at' => '2026-05-16T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'so'),
            'stderr_hash' => hash('sha256', $arm.'se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => 0.01,
            'tokens_used' => 100,
            'worktree' => '/tmp/work',
            'case_id' => 'synthetic-case',
            'changed_files' => ['tests/Feature/Synthetic.php', 'app/Synthetic.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'pd'),
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
        ], $overrides);
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'sanity-v1-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
            $p = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($p) ? $this->purge($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
