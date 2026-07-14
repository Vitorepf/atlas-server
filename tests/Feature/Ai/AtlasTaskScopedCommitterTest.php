<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateToken;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PART 2 · shared-main scoped committer — proves an AI's resolve commits ONLY its own files, never sweeping a
 * neighbour's uncommitted work, and never a pétreo forbidden target. Runs in a THROWAWAY git repo (never the
 * real one).
 */
final class AtlasTaskScopedCommitterTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-scoped-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
        $this->createBlackboardTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    public function test_commits_only_the_scope_never_a_neighbours_changes(): void
    {
        // Two AIs touched the SAME working tree: AI-A's file + AI-B's file are both changed at once.
        $this->writeFile('app/A/Alpha.php', "<?php // A\n");
        $this->writeFile('app/B/Beta.php', "<?php // B (a neighbour's uncommitted work)\n");

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $res = $committer->commitScope(['app/A/Alpha.php'], 'task-a', 'client-alpha', 'do A');

        $this->assertTrue($res['committed'], 'AI-A committed its file');
        $this->assertSame(['app/A/Alpha.php'], $res['files_committed']);

        // The commit contains ONLY Alpha.php; Beta.php is still uncommitted (the neighbour keeps its work).
        $committed = trim($this->git(['show', '--name-only', '--pretty=format:', 'HEAD'])['out']);
        $this->assertStringContainsString('app/A/Alpha.php', $committed);
        $this->assertStringNotContainsString('app/B/Beta.php', $committed, 'a neighbour\'s file is NEVER swept into this commit');

        $stillDirty = trim($this->git(['status', '--porcelain', '--untracked-files=all'])['out']);
        $this->assertStringContainsString('app/B/Beta.php', $stillDirty, 'Beta.php remains for AI-B to commit itself');

        // The commit is attributed to the AI.
        $msg = $this->git(['log', '-1', '--pretty=%B'])['out'];
        $this->assertStringContainsString('Resolved-by: client-alpha', $msg);
        $this->assertStringContainsString('Atlas-Task: task-a', $msg);
    }

    public function test_refuses_a_forbidden_self_target(): void
    {
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php';
        $this->writeFile($forbidden, "<?php // tampering\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope([$forbidden], 'task-x', 'client-x');

        $this->assertFalse($res['committed'], 'the loop\'s own governed-merge service can never be committed by a served task');
        $this->assertSame('forbidden_self_target', $res['reason']);
        // Nothing was committed.
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']));
    }

    public function test_commits_file_with_non_ascii_path(): void
    {
        // ó = U+00F3 (UTF-8: \xc3\xb3). git quotes/octal-escapes this in non--z porcelain,
        // causing git add to fail with the old trim-quotes parser.
        $filename = 'app/relat'."\xc3\xb3".'rio.php'; // relatório.php

        $this->writeFile($filename, "<?php // non-ASCII path\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope([$filename], 'task-nc', 'client-nc', 'non-ascii commit');

        $this->assertTrue($res['committed'], 'file with non-ASCII path must commit; reason: '.(string) ($res['reason'] ?? 'none'));
        $this->assertContains($filename, $res['files_committed']);
    }

    public function test_normalizes_scoped_paths_and_rejects_traversal_before_git_effect(): void
    {
        $this->writeFile('app/A/Normalized.php', "<?php // normalized\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [' /app\\A\\Normalized.php ', '../escape.php', '', 'app/A/Normalized.php'],
            'task-normalize', 'client-normalize', 'normalize scope',
        );

        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertSame(['app/A/Normalized.php'], $res['files_committed']);
        $this->assertStringNotContainsString('escape.php', $this->git(['show', '--name-only', '--pretty=format:', 'HEAD'])['out']);
    }

    public function test_default_commit_path_acquires_the_canonical_main_merge_lock(): void
    {
        $this->writeFile('app/A/Locked.php', "<?php // locked\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/A/Locked.php'], 'task-lock-default', 'client-lock', 'lock default',
        );

        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertFileExists($this->repo.'/.git/'.\App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator::LOCK_BASENAME);
    }

    public function test_pre_effect_revalidation_failure_has_zero_git_effect(): void
    {
        $this->writeFile('app/A/Revalidated.php', "<?php // revalidation\n");
        $head = trim($this->git(['rev-parse', 'HEAD'])['out']);

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/A/Revalidated.php'], 'task-revalidate', 'client-revalidate', 'revalidate',
            preEffectGuard: static fn (): bool => false,
        );

        $this->assertFalse($res['committed']);
        $this->assertSame('governed_pre_effect_revalidation_failed', $res['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])['out']));
    }

    public function test_commit_message_sanitizes_newlines_and_truncates_long_objectives(): void
    {
        $this->writeFile('app/A/Message.php', "<?php // message\n");
        $objective = "  ".str_repeat('á', 80)."\nnext\rline  ";

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/A/Message.php'], 'task-message', 'client-message', $objective,
        );

        $this->assertTrue($res['committed'], json_encode($res));
        $subject = strtok($this->git(['log', '-1', '--pretty=%s'])['out'], "\n");
        $this->assertStringNotContainsString("\n", (string) $subject);
        $this->assertStringNotContainsString("\r", (string) $subject);
        $this->assertLessThanOrEqual(72, mb_strlen((string) $subject) - mb_strlen('atlas-task task-message: '));
        $this->assertStringEndsWith('...', (string) $subject);
    }

    public function test_successful_landing_records_live_outcome_feedback_when_writer_is_bound(): void
    {
        $log = sys_get_temp_dir().'/atlas-scoped-feedback-'.bin2hex(random_bytes(5)).'.jsonl';
        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($log);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $feedback);
        $this->writeFile('app/A/Feedback.php', "<?php // feedback\n");

        try {
            $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
                ['app/A/Feedback.php'], 'task-feedback', 'autonomos_worker', 'feedback landing',
                verification: [
                    'proof_strength' => 'boot_proven',
                    'execution_evidence' => [
                        'commands' => ['php artisan test tests/ExampleTest.php'],
                        'claimed_status' => 'passed',
                        'tests_run' => 1,
                        'assertions_executed' => 1,
                        'selected_tests' => ['tests/ExampleTest.php'],
                        'counts_parseable' => true,
                    ],
                ],
            );

            $this->assertTrue($res['committed'], json_encode($res));
            $this->assertSame('autonomos_landing', data_get($res, 'live_outcome_feedback.role'));
            $this->assertSame('autonomos_worker', data_get($res, 'live_outcome_feedback.provider'));
            $this->assertTrue((bool) data_get($res, 'live_outcome_feedback.proven_real'));
            $this->assertSame('server_verified', data_get($res, 'live_outcome_feedback.verified_basis'));
            $this->assertSame('task-feedback', data_get($res, 'live_outcome_feedback.certified_receipt_id'));
            $this->assertSame(1.0, data_get($res, 'live_outcome_feedback.quality_score'));
            $this->assertFileExists($log);
        } finally {
            @unlink($log);
        }
    }

    public function test_empty_objective_uses_the_canonical_default_commit_summary(): void
    {
        $this->writeFile('app/A/DefaultSummary.php', "<?php // default summary\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/A/DefaultSummary.php'], 'task-default-summary', 'client-default',
        );

        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertSame(
            'atlas-task task-default-summary: resolve task',
            trim($this->git(['log', '-1', '--pretty=%s'])['out']),
        );
    }

    public function test_objective_at_the_summary_boundary_is_not_truncated_but_next_character_is(): void
    {
        $exactPath = 'app/A/ExactSummary.php';
        $this->writeFile($exactPath, "<?php // exact summary\n");
        $exact = str_repeat('x', 72);
        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [$exactPath], 'task-exact-summary', 'client-exact', $exact,
        );
        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertSame(
            'atlas-task task-exact-summary: '.$exact,
            trim($this->git(['log', '-1', '--pretty=%s'])['out']),
        );

        $nextPath = 'app/A/NextSummary.php';
        $this->writeFile($nextPath, "<?php // next summary\n");
        $next = str_repeat('y', 73);
        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [$nextPath], 'task-next-summary', 'client-next', $next,
        );
        $this->assertTrue($res['committed'], json_encode($res));
        $this->assertSame(
            'atlas-task task-next-summary: '.str_repeat('y', 69).'...',
            trim($this->git(['log', '-1', '--pretty=%s'])['out']),
        );
    }

    public function test_nothing_to_commit_in_scope_is_an_honest_noop(): void
    {
        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(['app/A/Unchanged.php'], 'task-n', 'client-n');

        $this->assertFalse($res['committed']);
        $this->assertSame('nothing_to_commit_in_scope', $res['reason']);
    }

    /** A NEW file inside the self-edit zone — NOT on the pétreo denylist (the fail-open hole SEV-1 closed). */
    private const SELF_EDIT = 'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainBrandNewOrgan.php';

    public function test_a_self_edit_without_a_constitution_token_is_blocked_fail_closed(): void
    {
        $this->writeFile(self::SELF_EDIT, "<?php // self-edit\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope([self::SELF_EDIT], 'task-se', 'client-se');

        $this->assertFalse($res['committed'], 'a self-edit without a constitutional MACHINE verdict never lands');
        $this->assertSame('constitution_gate_blocked_self_edit_no_token', $res['reason']);
        $this->assertSame([self::SELF_EDIT], $res['self_edit_paths']);
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']), 'no commit landed');
    }

    public function test_a_self_edit_with_a_machine_pass_token_bound_to_the_post_apply_tree_commits(): void
    {
        $this->writeFile(self::SELF_EDIT, "<?php // gated self-edit\n");

        // The MACHINE mints the PASS-token for the exact post-apply tree (never human approval).
        $this->git(['add', '--', self::SELF_EDIT]);
        $tree = trim($this->git(['write-tree'])['out']);
        $token = (new AtlasLoopConstitutionGateToken)->mint($tree, 'battery-1', 'PASS', 'nonce-1');

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [self::SELF_EDIT], 'task-se2', 'client-se2', 'gated self-edit',
            constitution: ['token' => $token, 'battery_root' => 'battery-1', 'nonce' => 'nonce-1'],
        );

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
        $this->assertContains(self::SELF_EDIT, $res['files_committed']);
    }

    public function test_a_self_edit_with_a_token_bound_to_a_different_tree_does_not_commit(): void
    {
        $this->writeFile(self::SELF_EDIT, "<?php // forged bind\n");
        $token = (new AtlasLoopConstitutionGateToken)->mint(str_repeat('a', 40), 'battery-1', 'PASS', 'nonce-1');

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [self::SELF_EDIT], 'task-se3', 'client-se3', 'forged',
            constitution: ['token' => $token, 'battery_root' => 'battery-1', 'nonce' => 'nonce-1'],
        );

        $this->assertFalse($res['committed']);
        $this->assertStringStartsWith('constitution_token_invalid', (string) $res['reason']);
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']), 'no commit landed');
    }

    public function test_the_operator_kill_switch_disables_the_constitution_gate(): void
    {
        config(['atlas.loop.constitution_gate_enabled' => false]);
        $this->writeFile(self::SELF_EDIT, "<?php // kill-switch\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope([self::SELF_EDIT], 'task-ks', 'client-ks');

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
    }

    public function test_the_operator_port_lands_property_gated_paths_without_a_token(): void
    {
        $this->writeFile(self::SELF_EDIT, "<?php // operator landing (atlas:land)\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            [self::SELF_EDIT], 'task-op', 'client-op', 'operator landing', commitAuthority: 'operator',
        );

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
    }

    public function test_successful_commit_releases_active_blackboard_claims_for_committed_paths(): void
    {
        $blackboard = $this->app->make(AtlasAobgBlackboardService::class);
        $claim = $blackboard->claim('client-alpha', 'file', 'app/A/Alpha.php', ['ttl' => 60]);
        $this->assertTrue($claim['ok']);

        $this->writeFile('app/A/Alpha.php', "<?php // A\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo, blackboard: $blackboard))
            ->commitScope(['app/A/Alpha.php'], 'task-a', 'client-alpha', 'do A');

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
        $this->assertSame(1, $res['blackboard_claim_release']['released_count']);
        $this->assertSame(0, $blackboard->conflictsFor('app/A/Alpha.php')['count']);
        $this->assertSame('released', DB::table('atlas_aobg_blackboard')->where('id', $claim['claim']['id'])->value('status'));
    }

    public function test_failed_commit_does_not_release_blackboard_claims(): void
    {
        $blackboard = $this->app->make(AtlasAobgBlackboardService::class);
        $claim = $blackboard->claim('client-empty', 'file', 'app/A/Missing.php', ['ttl' => 60]);
        $this->assertTrue($claim['ok']);

        $res = (new AtlasTaskScopedCommitter(null, $this->repo, blackboard: $blackboard))
            ->commitScope(['app/A/Missing.php'], 'task-empty', 'client-empty');

        $this->assertFalse($res['committed']);
        $this->assertSame('nothing_to_commit_in_scope', $res['reason']);
        $this->assertSame('active', DB::table('atlas_aobg_blackboard')->where('id', $claim['claim']['id'])->value('status'));
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    private function createBlackboardTable(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');

        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
