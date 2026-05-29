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

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for merge retry queue tests.');
        }
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
