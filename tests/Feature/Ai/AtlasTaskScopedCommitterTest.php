<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgBlackboardService;
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
