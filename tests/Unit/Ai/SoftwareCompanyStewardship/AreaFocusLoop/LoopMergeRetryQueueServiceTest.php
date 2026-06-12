<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopMergeRetryQueueService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for LoopMergeRetryQueueService.
 *
 * Tests that need real git operations create an isolated temp repo and inject
 * it via setRepoRootForTesting(). Tests that only need queue file semantics
 * work with the in-memory JSONL directly.
 */
final class LoopMergeRetryQueueServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_merge_retry_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        // L2-11: a drenagem auto-mergeadora agora é fail-closed (default OFF). Estes testes
        // exercitam a MECÂNICA de merge (válida quando habilitada) — ligam a flag; o teste
        // do default fail-closed a desliga explicitamente.
        config(['atlas.stewardship.retry_queue_auto_merge' => true]);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for merge retry queue tests.');
        }
    }

    public function test_default_off_escalates_instead_of_merging_to_main_fail_closed(): void
    {
        // L2-11 (achado CRÍTICO): sem a flag, NENHUM branch é mergeado às cegas; pendentes
        // escalam p/ revisão humana (a re-validação contra a base atual é pré-requisito; o
        // caminho governado e re-provado é o AtlasLoopAutoMergeService — merge-livre v2).
        config(['atlas.stewardship.retry_queue_auto_merge' => false]);

        $repo = $this->initRepo('repo_failclosed');
        $this->runGit(['git', 'checkout', '-b', 'atlas/should-not-merge'], $repo);
        file_put_contents($repo.'/app/X.php', "<?php\nclass X {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'x'], $repo);
        $this->runGit(['git', 'checkout', 'main'], $repo);

        $svc = $this->service($repo);
        $svc->enqueue('atlas/should-not-merge', 'fk-x', 'diff x', 'ff_only_merge_failed');

        $result = $svc->processQueue('main');

        $this->assertSame([], $result['merged'], 'fail-closed: nada mergeia às cegas');
        $this->assertContains('atlas/should-not-merge', $result['escalated'], 'pendentes escalam p/ revisão');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function service(?string $repoRoot = null): LoopMergeRetryQueueService
    {
        $svc = new LoopMergeRetryQueueService();
        $svc->setStorageRootForTesting($this->tmp.'/queue_storage');
        if ($repoRoot !== null) {
            $svc->setRepoRootForTesting($repoRoot);
        }

        return $svc;
    }

    private function runGit(array $cmd, string $cwd): void
    {
        $p = new Process($cmd, $cwd);
        $p->setTimeout(30);
        $p->run();
    }

    /**
     * Create a bare repo with an initial commit on `main`.
     */
    private function initRepo(string $suffix = 'repo'): string
    {
        $repo = $this->tmp.'/'.$suffix;
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/app/Base.php', "<?php\nclass Base {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
    }

    // ── enqueue ──────────────────────────────────────────────────────────────

    public function test_enqueue_appends_pending_item_to_queue(): void
    {
        $svc = $this->service();

        $svc->enqueue('atlas/my-branch', 'finding-001', 'diff content', 'ff_only_merge_failed');

        $this->assertTrue($svc->hasPending());
        $this->assertSame(1, $svc->pendingCount());
    }

    public function test_enqueue_multiple_items_all_appear_as_pending(): void
    {
        $svc = $this->service();

        $svc->enqueue('atlas/branch-a', 'finding-a', 'diff-a', 'ff_only_merge_failed');
        $svc->enqueue('atlas/branch-b', 'finding-b', 'diff-b', 'base_worktree_dirty');

        $this->assertSame(2, $svc->pendingCount());
    }

    public function test_enqueue_deduplicates_same_pending_branch_and_diff(): void
    {
        $svc = $this->service();

        $svc->enqueue('atlas/branch-a', 'finding-a', 'stable-branch-tip', 'base_worktree_dirty');
        $svc->enqueue('atlas/branch-a', 'finding-a', 'stable-branch-tip', 'base_worktree_dirty');

        $this->assertSame(1, $svc->pendingCount());
    }

    public function test_enqueue_sets_correct_schema_and_zero_attempts(): void
    {
        $svc = $this->service();
        $svc->enqueue('atlas/br', 'fk-1', 'the diff', 'ff_only_merge_failed');

        $path = $svc->queuePath();
        $this->assertFileExists($path);

        $line = trim((string) file_get_contents($path));
        $item = json_decode($line, true);

        $this->assertSame(LoopMergeRetryQueueService::QUEUE_SCHEMA, $item['schema_version']);
        $this->assertSame('atlas/br', $item['branch']);
        $this->assertSame('fk-1', $item['finding_key']);
        $this->assertSame(0, $item['attempts']);
        $this->assertSame(LoopMergeRetryQueueService::STATUS_PENDING, $item['status']);
        $this->assertStringStartsWith('sha256:', $item['diff_hash']);
    }

    // ── hasPending / pendingCount ────────────────────────────────────────────

    public function test_has_pending_returns_false_when_queue_is_empty(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->hasPending());
        $this->assertSame(0, $svc->pendingCount());
    }

    // ── processQueue — successful retry ─────────────────────────────────────

    public function test_process_queue_merges_pending_branch_on_success(): void
    {
        $repo = $this->initRepo('repo_success');

        // Create feature branch with a new commit on top of main.
        $this->runGit(['git', 'checkout', '-b', 'atlas/feature-ok'], $repo);
        file_put_contents($repo.'/app/Feature.php', "<?php\nclass Feature {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'feat: add Feature'], $repo);
        $this->runGit(['git', 'checkout', 'main'], $repo);

        $svc = $this->service($repo);
        $svc->enqueue('atlas/feature-ok', 'fk-ok', 'diff ok', 'ff_only_merge_failed');

        $result = $svc->processQueue('main');

        $this->assertContains('atlas/feature-ok', $result['merged']);
        $this->assertEmpty($result['escalated']);
        $this->assertFalse($svc->hasPending());
    }

    // ── processQueue — rebase then merge ─────────────────────────────────────

    public function test_process_queue_rebases_diverged_branch_then_merges(): void
    {
        $repo = $this->initRepo('repo_rebase');

        // Create feature branch from current main.
        $this->runGit(['git', 'checkout', '-b', 'atlas/diverged'], $repo);
        file_put_contents($repo.'/app/Diverged.php', "<?php\nclass Diverged {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'feat: diverged'], $repo);

        // Advance main with a non-conflicting change so branch is now behind.
        $this->runGit(['git', 'checkout', 'main'], $repo);
        file_put_contents($repo.'/app/MainAdvance.php', "<?php\nclass MainAdvance {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'chore: advance main'], $repo);

        $svc = $this->service($repo);
        $svc->enqueue('atlas/diverged', 'fk-diverged', 'diff-diverged', 'branch_not_rebased_on_current_base');

        $result = $svc->processQueue('main');

        $this->assertContains('atlas/diverged', $result['merged']);
        $this->assertEmpty($result['escalated']);
    }

    /**
     * L3-9 #2: a branch that rebases cleanly (non-conflicting paths) but carries
     * COMMITTED conflict markers must NOT be fast-forward merged — the rebase "success"
     * does not mean the content is safe. The post-rebase cleanliness check catches the
     * markers and the item escalates to the operator instead of merging poisoned content.
     */
    public function test_rebase_success_with_committed_conflict_markers_escalates_not_merges(): void
    {
        $repo = $this->initRepo('repo_marker');

        // Branch adds a NEW file that contains committed conflict-marker text. This file
        // does not exist on main, so the rebase onto main is conflict-free (it just
        // replays the commit) — yet the merged content would be poisoned.
        $this->runGit(['git', 'checkout', '-b', 'atlas/poisoned'], $repo);
        $poison = "<?php\n// generated\n<<<<<<< HEAD\n\$a = 1;\n=======\n\$a = 2;\n>>>>>>> theirs\n";
        file_put_contents($repo.'/app/Poisoned.php', $poison);
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'feat: poisoned (committed conflict markers)'], $repo);

        // Advance main on an UNRELATED file so the branch is behind and a rebase is taken.
        $this->runGit(['git', 'checkout', 'main'], $repo);
        file_put_contents($repo.'/app/MainAdvance.php', "<?php\nclass MainAdvance {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'chore: advance main'], $repo);

        $svc = $this->service($repo);
        $svc->enqueue('atlas/poisoned', 'fk-poison', 'diff-poison', 'branch_not_rebased_on_current_base');

        // maxAttempts=1 so the single attempt's verdict is terminal.
        $result = $svc->processQueue('main', 1);

        $this->assertEmpty($result['merged'], 'poisoned branch must NOT merge after rebase');
        $this->assertContains('atlas/poisoned', $result['escalated'], 'must escalate to operator');

        // main must not have advanced to the branch (no merge landed).
        $mainHead = trim((new Process(['git', 'rev-parse', 'main'], $repo))->mustRun()->getOutput());
        $branchHead = trim((new Process(['git', 'rev-parse', 'atlas/poisoned'], $repo))->mustRun()->getOutput());
        $this->assertNotSame($branchHead, $mainHead);
    }

    // ── processQueue — escalation after maxAttempts ──────────────────────────

    public function test_process_queue_escalates_after_max_attempts_on_conflict(): void
    {
        $repo = $this->initRepo('repo_conflict');

        // Create branch with a change on Conflict.php.
        $this->runGit(['git', 'checkout', '-b', 'atlas/conflict-branch'], $repo);
        file_put_contents($repo.'/app/Conflict.php', "<?php\nclass ConflictBranch {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'feat: conflict side'], $repo);

        // Advance main with a conflicting change on the SAME file.
        $this->runGit(['git', 'checkout', 'main'], $repo);
        file_put_contents($repo.'/app/Conflict.php', "<?php\nclass ConflictMain {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'chore: conflict on main'], $repo);

        $svc = $this->service($repo);
        $svc->enqueue('atlas/conflict-branch', 'fk-conflict', 'diff-conflict', 'ff_only_merge_failed');

        // maxAttempts=1: first attempt must escalate (rebase conflict).
        $result = $svc->processQueue('main', 1);

        $this->assertContains('atlas/conflict-branch', $result['escalated']);
        $this->assertEmpty($result['merged']);
        $this->assertFalse($svc->hasPending());
    }

    // ── getEscalated ─────────────────────────────────────────────────────────

    public function test_get_escalated_returns_only_escalated_items(): void
    {
        $svc = $this->service();

        // Manually seed the queue file with one pending and one escalated item.
        File::ensureDirectoryExists($svc->storageDir());
        $pending = [
            'schema_version' => LoopMergeRetryQueueService::QUEUE_SCHEMA,
            'branch' => 'atlas/pending-branch',
            'finding_key' => 'fk-p',
            'diff_hash' => 'sha256:abc',
            'accepted_at' => '2026-05-29T10:00:00+00:00',
            'merge_attempt_reason' => 'test',
            'attempts' => 0,
            'status' => LoopMergeRetryQueueService::STATUS_PENDING,
            'last_failure_reason' => null,
            'escalated_at' => null,
            'merged_at' => null,
        ];
        $escalated = [
            'schema_version' => LoopMergeRetryQueueService::QUEUE_SCHEMA,
            'branch' => 'atlas/escalated-branch',
            'finding_key' => 'fk-e',
            'diff_hash' => 'sha256:def',
            'accepted_at' => '2026-05-29T09:00:00+00:00',
            'merge_attempt_reason' => 'test',
            'attempts' => 2,
            'status' => LoopMergeRetryQueueService::STATUS_ESCALATED,
            'last_failure_reason' => 'rebase_failed',
            'escalated_at' => '2026-05-29T09:30:00+00:00',
            'merged_at' => null,
        ];
        file_put_contents(
            $svc->queuePath(),
            json_encode($pending).PHP_EOL.json_encode($escalated).PHP_EOL,
        );

        $escalatedItems = $svc->getEscalated();

        $this->assertCount(1, $escalatedItems);
        $this->assertSame('atlas/escalated-branch', $escalatedItems[0]['branch']);
        $this->assertSame(LoopMergeRetryQueueService::STATUS_ESCALATED, $escalatedItems[0]['status']);
        // Pending is still pending.
        $this->assertSame(1, $svc->pendingCount());
    }
}
