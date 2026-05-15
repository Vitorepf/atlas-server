<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Adjudicator (deterministic, local-only) contract tests.
 *
 * Builds synthetic evidence packs on disk and exercises the heuristics:
 *   - infrastructure/evidence hard-fail forces score=null, winner=null
 *   - one-sided deterministic test failure produces gate_winner with score=null and claim_ready=false
 *   - quality scoring fires only when every hard gate is green
 *   - statistical tie ⇒ winner=human_review_required_tie
 *   - clear quality lead ⇒ winner=atlas|rival with structured reason
 *   - every code path stays read-only and never invokes a provider
 */
final class AtlasForgeRivalsAdjudicatorServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsAdjudicatorService $adjudicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-adjudicator-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $replay = new AtlasForgeRivalsReplayService($this->paths, new AtlasForgeRivalsEventStream($this->paths));
        $this->adjudicator = new AtlasForgeRivalsAdjudicatorService($this->paths, $replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_blocks_when_run_id_missing(): void
    {
        $result = $this->adjudicator->adjudicate([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_id_required', $result['blockers']);
    }

    public function test_blocks_when_run_not_found(): void
    {
        $result = $this->adjudicator->adjudicate(['run_id' => 'nonexistent-run']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_not_found:nonexistent-run', $result['blockers']);
    }

    public function test_hard_fail_when_replay_fails_forces_null_score_and_null_winner(): void
    {
        $runId = $this->newRunId('hard-fail-replay');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, atlasOverrides: [], rivalOverrides: []);
        // Corrupt the manifest so replay's hash check fails on a known artifact.
        file_put_contents($paths['evidence'].'/atlas_receipt.json', '{"corrupted":true}');

        // Rebuild evidence_pack to a stale hash so replay mismatches.
        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = json_decode((string) file_get_contents($packPath), true);
        $pack['artifacts']['atlas_receipt']['sha256'] = str_repeat('0', 64);
        file_put_contents($packPath, json_encode($pack, JSON_PRETTY_PRINT));

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);

        $this->assertSame('ok', $result['status']);
        $scorecard = $result['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertNotEmpty($scorecard['hard_failures']);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertTrue($scorecard['separated_from_external_rivals_certification']);
    }

    public function test_hard_fail_when_out_of_scope_files_present(): void
    {
        $runId = $this->newRunId('hard-fail-oos');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['out_of_scope_files' => ['src/sneaky.php']],
            rivalOverrides: [],
        );

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $scorecard = $result['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertContains('no_out_of_scope_files_atlas', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_bytecode_artifacts_present(): void
    {
        $runId = $this->newRunId('hard-fail-pyc');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [],
            rivalOverrides: ['bytecode_artifacts' => ['foo/__pycache__/bar.pyc']],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('no_bytecode_artifacts_rival', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_dirty_after_run(): void
    {
        $runId = $this->newRunId('hard-fail-dirty');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, [], [], manifestOverrides: ['dirty_after_run' => true]);

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('dirty_after_run_false', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_patch_diff_missing(): void
    {
        $runId = $this->newRunId('hard-fail-nopatch');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_bytes' => 0],
            rivalOverrides: [],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('patch_diff_present_atlas', $scorecard['hard_failures']);
    }

    public function test_one_sided_test_failure_produces_gate_winner_without_quality_score_or_external_claim(): void
    {
        $runId = $this->newRunId('rival-test-fails');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['test_exit_code' => 0],
            rivalOverrides: ['test_exit_code' => 2, 'test_log_tail' => 'FAILED Tests\\Feature\\SyntheticTest'],
            manifestOverrides: ['verdict' => 'invalid_tests_failed', 'claim_ready' => false],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['gate_winner']);
        $this->assertSame('gate_outcome', $scorecard['score_source']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['quality_score_available']);
        $this->assertSame('one_sided_test_failure', $scorecard['gate_result']['kind']);
        $this->assertContains('tests_passed_rival', $scorecard['hard_failures']);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertTrue($scorecard['separated_from_external_rivals_certification']);
    }

    public function test_atlas_wins_when_patch_focus_and_scope_clearly_better(): void
    {
        $runId = $this->newRunId('atlas-wins');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_bytes' => 1_500,
                'changed_files' => ['tests/Feature/Foo.php', 'app/Foo.php'],
                'test_log_tail' => '(150 tests, 320 assertions)',
            ],
            rivalOverrides: [
                'patch_diff_bytes' => 120_000,
                'changed_files' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n'],
                'test_log_tail' => '(5 tests, 10 assertions)',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['winner']);
        $this->assertGreaterThan($scorecard['rival_score'], $scorecard['atlas_score']);
        $this->assertNotEmpty($scorecard['winner_reason']);
        $this->assertTrue($scorecard['claim_ready']);
        $this->assertFalse($scorecard['human_review_required']);
    }

    public function test_rival_wins_when_test_quality_and_focus_clearly_better(): void
    {
        $runId = $this->newRunId('rival-wins');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_bytes' => 180_000,
                'changed_files' => array_map(static fn (int $i): string => 'src/big_'.$i.'.php', range(1, 15)),
                'test_log_tail' => '(2 tests, 4 assertions)',
            ],
            rivalOverrides: [
                'patch_diff_bytes' => 1_400,
                'changed_files' => ['tests/Feature/Bar.php', 'app/Bar.php'],
                'test_log_tail' => '(200 tests, 800 assertions)',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL, $scorecard['winner']);
        $this->assertGreaterThan($scorecard['atlas_score'], $scorecard['rival_score']);
        $this->assertTrue($scorecard['claim_ready']);
    }

    public function test_statistical_tie_when_arms_equivalent(): void
    {
        $runId = $this->newRunId('tie');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_bytes' => 2_500, 'test_log_tail' => '(20 tests, 50 assertions)'],
            rivalOverrides: ['patch_diff_bytes' => 2_500, 'test_log_tail' => '(20 tests, 50 assertions)'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $scorecard['winner']);
        $this->assertTrue($scorecard['human_review_required']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_scorecard_persists_to_evidence_directory(): void
    {
        $runId = $this->newRunId('persist');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, [], []);

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);

        $this->assertFileExists($paths['scorecard_json']);
        $persisted = json_decode((string) file_get_contents($paths['scorecard_json']), true);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION, $persisted['schema_version']);
        $this->assertSame($paths['scorecard_json'], $result['scorecard_path']);
    }

    public function test_weights_explicit_and_sum_to_one(): void
    {
        $total = 0.0;
        foreach (AtlasForgeRivalsAdjudicatorService::WEIGHTS as $w) {
            $total += $w;
        }
        $this->assertEqualsWithDelta(1.0, $total, 0.0001, 'Adjudicator weights must sum to 1.0.');
    }

    /**
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $atlasOverrides
     * @param  array<string,mixed>  $rivalOverrides
     * @param  array<string,mixed>  $manifestOverrides
     */
    private function seedComparableRun(
        array $paths,
        array $atlasOverrides,
        array $rivalOverrides,
        array $manifestOverrides = [],
    ): void {
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $atlasReceipt = $this->baseReceipt('atlas', $atlasOverrides);
        $rivalReceipt = $this->baseReceipt('rival', $rivalOverrides);

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        $manifest = array_replace([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'verdict' => 'comparable',
            'score' => null,
            'claim_ready' => true,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
        ], $manifestOverrides);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        // Minimal events.jsonl
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic']));

        // Build the evidence_pack with valid hashes for replay.
        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }
        $pack = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => $paths['run_id'],
            'collected_at' => date('c'),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => [],
            'verdict' => 'comparable',
            'claim_ready' => true,
        ];
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode($pack));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseReceipt(string $arm, array $overrides): array
    {
        $base = [
            'arm' => $arm,
            'mode' => 'local_fake',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm),
            'prompt_hash' => hash('sha256', $arm.'p'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
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
        ];

        return array_replace($base, $overrides);
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'adj-test-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
