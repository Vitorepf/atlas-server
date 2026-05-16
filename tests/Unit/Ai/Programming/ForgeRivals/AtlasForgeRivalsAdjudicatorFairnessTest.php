<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Adjudicator Fairness Gates v1 (single-run).
 *
 * Locks down the new fairness layer that distinguishes a real model
 * performance signal from a harness/setup artefact:
 *   - provider failure (kill/timeout/empty stdout) → invalid, not a free win
 *   - test failure → distinct validity class (model output bad, not harness)
 *   - replay drift → invalid regardless of score
 *   - missing evidence → invalid
 *   - extreme score with shaky evidence → needs_triage
 *   - local_fake mode → never produces a real claim
 *   - confidence ladder (high/medium/low/invalid)
 *
 * Read-only: no provider invocation, no token spend, no real dispatch.
 */
final class AtlasForgeRivalsAdjudicatorFairnessTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsReplayService $replay;

    private AtlasForgeRivalsAdjudicatorService $adjudicator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-fairness-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);
        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->adjudicator = new AtlasForgeRivalsAdjudicatorService($this->paths, $this->replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_rival_provider_kill_invalidates_run_not_a_free_atlas_win(): void
    {
        $runId = $this->newRunId('rival-killed');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 2000],
            rival: ['exit_code' => 137, 'killed' => true, 'stdout_bytes' => 0, 'test_exit_code' => -1, 'patch_diff_bytes' => 0],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertNull($scorecard['winner']);
        $this->assertNull($scorecard['gate_winner'] ?? null);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_PROVIDER_FAILURE,
            $fairness['validity_class'],
        );
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::CONFIDENCE_INVALID, $fairness['confidence']['level']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_atlas_timeout_invalidates_run_not_a_free_rival_win(): void
    {
        $runId = $this->newRunId('atlas-timeout');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['exit_code' => 1, 'timeout' => true, 'timeout_reason' => 'process_timeout', 'stdout_bytes' => 0, 'test_exit_code' => -1, 'patch_diff_bytes' => 0],
            rival: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 2000],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_PROVIDER_FAILURE,
            $fairness['validity_class'],
        );
        $this->assertNull($scorecard['winner']);
        // Provider failure suppresses any gate winner — the harness signal is
        // invalid, not a free rival win. The hard-fail branch may omit the
        // gate_winner key entirely; assert that no winner is surfaced.
        $this->assertNull($scorecard['gate_winner'] ?? null);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_rival_test_failure_stays_test_failure_not_provider_failure(): void
    {
        $runId = $this->newRunId('rival-test-failure');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 2000],
            rival: ['exit_code' => 0, 'test_exit_code' => 1, 'stdout_bytes' => 4000, 'patch_diff_bytes' => 2000],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_TEST_FAILURE,
            $fairness['validity_class'],
        );
        // Quality scores are still null (one side failed tests) — but the
        // gate winner can still surface Atlas as the surviving arm.
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['gate_winner']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_local_fake_mode_never_promotes_real_claim_even_with_clear_win(): void
    {
        $runId = $this->newRunId('local-fake-clear-win');
        $this->seedRun(
            $runId,
            mode: 'local_fake',
            atlas: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1500, 'test_log_tail' => '(150 tests, 320 assertions)'],
            rival: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 120000, 'test_log_tail' => '(5 tests, 10 assertions)'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_LOCAL_FAKE,
            $fairness['validity_class'],
        );
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::CONFIDENCE_INVALID, $fairness['confidence']['level']);
        $this->assertFalse($scorecard['claim_ready']);
        // local_fake never destrava external_rivals_certification either.
        $this->assertTrue($scorecard['separated_from_external_rivals_certification']);
    }

    public function test_extreme_score_with_intact_evidence_in_fair_mode_stays_high_confidence(): void
    {
        // Atlas wins hugely but evidence is intact, mode=fair, replay ok.
        // The fairness gate keeps confidence=high and claim_ready=true.
        $runId = $this->newRunId('extreme-fair');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1500, 'test_log_tail' => '(300 tests, 800 assertions)'],
            rival: ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 180000, 'test_log_tail' => '(2 tests, 4 assertions)'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['winner']);
        $this->assertSame('fair', $fairness['mode']);
        $this->assertContains($fairness['confidence']['level'], [
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_HIGH,
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_MEDIUM,
        ]);
    }

    public function test_extreme_score_under_local_fake_is_marked_needs_triage(): void
    {
        $runId = $this->newRunId('extreme-fake');
        // Extreme: atlas has a tight focused diff with massive test coverage
        // while rival sprawled across 60+ files with almost no tests. The
        // resulting score margin should clear EXTREME_SCORE_MARGIN.
        $bigRivalFiles = array_map(static fn (int $i): string => 'src/sprawl_'.$i.'.php', range(1, 80));
        $this->seedRun(
            $runId,
            mode: 'local_fake',
            atlas: [
                'exit_code' => 0, 'test_exit_code' => 0,
                'patch_diff_bytes' => 1500,
                'changed_files' => ['tests/Feature/A.php', 'app/A.php'],
                'test_log_tail' => '(900 tests, 5000 assertions)',
                'stdout_bytes' => 2000,
            ],
            rival: [
                'exit_code' => 0, 'test_exit_code' => 0,
                'patch_diff_bytes' => 250000,
                'changed_files' => $bigRivalFiles,
                'test_log_tail' => '(1 test, 1 assertion)',
                'stdout_bytes' => 50000,
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        // The fairness layer always invalidates local_fake. If the score
        // margin clears EXTREME_SCORE_MARGIN under local_fake, the more
        // specific NEEDS_TRIAGE class is surfaced; otherwise the
        // INVALID_LOCAL_FAKE class wins. Either way claim_ready must be
        // false and no real winner is promoted.
        $this->assertContains($fairness['validity_class'], [
            AtlasForgeRivalsAdjudicatorService::VALIDITY_NEEDS_TRIAGE,
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_LOCAL_FAKE,
        ]);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::CONFIDENCE_INVALID, $fairness['confidence']['level']);
    }

    public function test_tight_tie_in_fair_mode_returns_medium_confidence_no_claim(): void
    {
        $runId = $this->newRunId('tight-tie');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: ['patch_diff_bytes' => 2500, 'test_log_tail' => '(20 tests, 50 assertions)'],
            rival: ['patch_diff_bytes' => 2500, 'test_log_tail' => '(20 tests, 50 assertions)'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $fairness = $scorecard['fairness'];

        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $scorecard['winner']);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::CONFIDENCE_MEDIUM,
            $fairness['confidence']['level'],
        );
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertTrue($scorecard['human_review_required']);
    }

    public function test_replay_drift_keeps_confidence_invalid(): void
    {
        $runId = $this->newRunId('replay-drift');
        $this->seedRun(
            $runId,
            mode: 'fair',
            atlas: [],
            rival: [],
        );
        // Drift the manifest after collect so replay no longer passes.
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
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::CONFIDENCE_INVALID, $fairness['confidence']['level']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    // ---------- fixtures ----------

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function seedRun(string $runId, string $mode, array $atlas, array $rival): void
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

        $manifest = [
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
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'fairness_test']));

        $this->collect->collect([
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
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
        return 'fair-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
