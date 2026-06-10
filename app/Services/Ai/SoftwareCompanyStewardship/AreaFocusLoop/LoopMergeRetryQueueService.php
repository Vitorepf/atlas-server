<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Symfony\Component\Process\Process;

/**
 * AP-790 · Loop Merge Retry Queue.
 *
 * Persists accepted-but-not-merged cycle branches across loop iterations so they
 * can be retried (with optional rebase) rather than discarded. A branch is
 * enqueued when the merge governor marks it auto_merge_eligible but the actual
 * ff-only merge could not proceed (dirty worktree, divergence, nothing-to-merge).
 * After two failed attempts the item is escalated — never silently dropped — so
 * the operator has full visibility in the next morning inbox.
 *
 * Storage: one flat JSONL per run-area, append-then-rewrite on status change.
 * The file is local-first and append-safe; no database or external service is used.
 */
final class LoopMergeRetryQueueService
{
    public const QUEUE_SCHEMA = 'atlas.software_company_stewardship.loop_merge_retry_queue.v1';

    public const STATUS_PENDING = 'pending';

    public const STATUS_MERGED = 'merged';

    public const STATUS_ESCALATED = 'escalated';

    private const DEFAULT_QUEUE_FILE = 'merge_retry_queue.jsonl';

    private ?string $storageRootOverride = null;

    private ?string $repoRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Override the repo root used for git operations (test seam only).
     */
    public function setRepoRootForTesting(?string $repoRoot): void
    {
        $this->repoRootOverride = $repoRoot;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/reliable_24h_loop')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/reliable_24h_loop';
    }

    public function queuePath(): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.self::DEFAULT_QUEUE_FILE;
    }

    /**
     * Enqueue an accepted-but-not-merged branch for retry.
     *
     * The diff_hash is a sha256 over the acceptedDiff so later replay can detect
     * if the branch was changed between acceptance and retry.
     */
    public function enqueue(string $branchName, string $findingKey, string $acceptedDiff, string $mergeAttemptReason): void
    {
        $diffHash = 'sha256:'.hash('sha256', $acceptedDiff);
        foreach ($this->loadAll() as $existing) {
            if (($existing['status'] ?? '') === self::STATUS_PENDING
                && (string) ($existing['branch'] ?? '') === $branchName
                && (string) ($existing['diff_hash'] ?? '') === $diffHash
            ) {
                return;
            }
        }

        $item = [
            'schema_version' => self::QUEUE_SCHEMA,
            'branch' => $branchName,
            'finding_key' => $findingKey,
            'diff_hash' => $diffHash,
            'accepted_at' => AreaFocusUtcClock::atomNow(),
            'merge_attempt_reason' => $mergeAttemptReason,
            'attempts' => 0,
            'status' => self::STATUS_PENDING,
            'last_failure_reason' => null,
            'escalated_at' => null,
            'merged_at' => null,
        ];

        AreaFocusAppendOnlyJsonlRecorder::append($this->queuePath(), $item);
    }

    /**
     * Process all pending items in the queue.
     *
     * For each pending item:
     * - If base has advanced since acceptance, attempt a rebase first, then merge.
     * - If the ff-only merge succeeds: mark merged.
     * - If it fails twice (attempts >= maxAttempts): mark escalated.
     *
     * Returns a summary with 'merged' and 'escalated' branch lists.
     *
     * @return array{merged: list<string>, escalated: list<string>, skipped: list<string>}
     */
    public function processQueue(string $baseBranch, int $maxAttempts = 2): array
    {
        $items = $this->loadAll();
        $merged = [];
        $escalated = [];
        $skipped = [];

        foreach ($items as &$item) {
            if (($item['status'] ?? '') !== self::STATUS_PENDING) {
                continue;
            }

            $repoRoot = $this->repoRootOverride ?? AreaFocusLoopPayloadNormalizer::repoRoot([]);
            $branchName = (string) ($item['branch'] ?? '');
            if ($branchName === '' || $repoRoot === '') {
                $skipped[] = $branchName;

                continue;
            }

            $item['attempts'] = ((int) ($item['attempts'] ?? 0)) + 1;

            // Try rebase if base has advanced (branch not rebased on base).
            $baseIsAncestor = $this->isAncestor($repoRoot, $baseBranch, $branchName);
            if (! $baseIsAncestor) {
                $rebaseOk = $this->tryRebase($repoRoot, $branchName, $baseBranch);
                if (! $rebaseOk) {
                    $item['last_failure_reason'] = 'rebase_failed';
                    if ($item['attempts'] >= $maxAttempts) {
                        $item['status'] = self::STATUS_ESCALATED;
                        $item['escalated_at'] = AreaFocusUtcClock::atomNow();
                        $escalated[] = $branchName;
                    }

                    continue;
                }
            }

            // Attempt ff-only merge.
            $mergeResult = $this->tryFfMerge($repoRoot, $baseBranch, $branchName);
            if ($mergeResult['success']) {
                $item['status'] = self::STATUS_MERGED;
                $item['merged_at'] = AreaFocusUtcClock::atomNow();
                $merged[] = $branchName;
            } else {
                $item['last_failure_reason'] = $mergeResult['reason'] ?? 'ff_merge_failed';
                if ($item['attempts'] >= $maxAttempts) {
                    $item['status'] = self::STATUS_ESCALATED;
                    $item['escalated_at'] = AreaFocusUtcClock::atomNow();
                    $escalated[] = $branchName;
                }
            }
        }
        unset($item);

        $this->rewrite($items);

        return [
            'merged' => $merged,
            'escalated' => $escalated,
            'skipped' => $skipped,
        ];
    }

    public function hasPending(): bool
    {
        foreach ($this->loadAll() as $item) {
            if (($item['status'] ?? '') === self::STATUS_PENDING) {
                return true;
            }
        }

        return false;
    }

    public function pendingCount(): int
    {
        $count = 0;
        foreach ($this->loadAll() as $item) {
            if (($item['status'] ?? '') === self::STATUS_PENDING) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Return all escalated items for operator visibility.
     *
     * @return list<array<string,mixed>>
     */
    public function getEscalated(): array
    {
        $escalated = [];
        foreach ($this->loadAll() as $item) {
            if (($item['status'] ?? '') === self::STATUS_ESCALATED) {
                $escalated[] = $item;
            }
        }

        return $escalated;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAll(): array
    {
        return AreaFocusJsonlReader::rows($this->queuePath());
    }

    /**
     * Rewrite the queue file from the in-memory items array (full rewrite on
     * mutation is safe because this file is small — one entry per cycle branch —
     * and is only written during processQueue, which runs serially in the loop).
     *
     * @param  list<array<string,mixed>>  $items
     */
    private function rewrite(array $items): void
    {
        AreaFocusJsonlWriter::rewrite($this->queuePath(), $items);
    }

    private function isAncestor(string $repoRoot, string $ancestor, string $descendant): bool
    {
        $process = new Process(['git', 'merge-base', '--is-ancestor', $ancestor, $descendant], $repoRoot);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array{success: bool, reason: string}
     */
    private function tryFfMerge(string $repoRoot, string $baseBranch, string $branchName): array
    {
        // Check working tree is clean before attempting merge.
        $status = new Process(['git', 'status', '--porcelain'], $repoRoot);
        $status->setTimeout(15);
        $status->run();
        if (! $status->isSuccessful() || trim($status->getOutput()) !== '') {
            return ['success' => false, 'reason' => 'base_worktree_dirty'];
        }

        $baseHeadBefore = $this->headCommit($repoRoot, $baseBranch);

        $checkout = new Process(['git', 'checkout', $baseBranch], $repoRoot);
        $checkout->setTimeout(30);
        $checkout->run();
        if (! $checkout->isSuccessful()) {
            return ['success' => false, 'reason' => 'checkout_base_failed'];
        }

        $merge = new Process(['git', 'merge', '--ff-only', $branchName], $repoRoot);
        $merge->setTimeout(120);
        $merge->run();
        if (! $merge->isSuccessful()) {
            return ['success' => false, 'reason' => 'ff_only_merge_failed'];
        }

        $newHead = $this->headCommit($repoRoot, $baseBranch);
        if ($newHead === '' || $newHead === $baseHeadBefore) {
            return ['success' => false, 'reason' => 'nothing_to_merge_branch_no_new_commits'];
        }

        return ['success' => true, 'reason' => ''];
    }

    /**
     * Attempt an interactive-safe rebase of the given branch onto the base.
     * Returns true only if the rebase completed without conflicts.
     */
    private function tryRebase(string $repoRoot, string $branchName, string $baseBranch): bool
    {
        // Checkout branch first.
        $checkout = new Process(['git', 'checkout', $branchName], $repoRoot);
        $checkout->setTimeout(30);
        $checkout->run();
        if (! $checkout->isSuccessful()) {
            return false;
        }

        $rebase = new Process(['git', 'rebase', $baseBranch], $repoRoot);
        $rebase->setTimeout(120);
        $rebase->run();
        if (! $rebase->isSuccessful()) {
            // Abort to leave working tree clean.
            $abort = new Process(['git', 'rebase', '--abort'], $repoRoot);
            $abort->setTimeout(30);
            $abort->run();

            return false;
        }

        return true;
    }

    private function headCommit(string $repoRoot, string $ref): string
    {
        $process = new Process(['git', 'rev-parse', '--verify', $ref], $repoRoot);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }
}
