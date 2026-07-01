<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Governor's missing REVERT leg. Given a task_packet_id, locates the single commit
 * {@see AtlasTaskScopedCommitter} landed for it (by scanning git log for the exact
 * "Atlas-Task: <id>" marker line that committer writes), and either plans (dry-run,
 * DEFAULT) or executes (`git revert`) undoing it — fail-closed on every ambiguity.
 *
 * SAFETY RAILS (each refuses with a distinct `reason`, before any git mutation):
 *   ambiguous_sha            — zero or more than one commit carries the exact marker.
 *   out_of_scope_file        — the commit touches a file outside the task's RECORDED
 *                               allowed_files scope (looked up by task_packet_id, not
 *                               re-derived from the commit itself).
 *   dirty_working_tree_file  — one of the commit's files has uncommitted working-tree
 *                               changes right now (another worker may be mid-task on it).
 *   forbidden_self_target    — any of the commit's files is pétreo
 *                               ({@see AtlasLoopHarnessGuard::isForbiddenSelfTarget}).
 *
 * Dry-run (default) never mutates git state: {sha, files, would_revert:true}. Live mode
 * (`$dryRun=false`) serializes with the SAME commit lock file/discipline
 * {@see AtlasTaskScopedCommitter::LOCK_REL} that landing commits use, then runs
 * `git revert --no-edit <sha>` and returns {reverted:true, revert_sha}.
 *
 * Every call — dry or live, success or refusal — appends one receipt through
 * {@see AtlasMergeGovernorReleaseDecisionLedger}.
 *
 * Canary logic, auto-trigger and scheduling are OUT of scope for this class: it only
 * plans/executes a single named revert on request.
 */
final class AtlasTaskMergeActuator
{
    public const SCHEMA = 'atlas.self_construction.governance.task_merge_actuator.v1';

    public const REASON_AMBIGUOUS_SHA = 'ambiguous_sha';

    public const REASON_OUT_OF_SCOPE_FILE = 'out_of_scope_file';

    public const REASON_DIRTY_WORKING_TREE_FILE = 'dirty_working_tree_file';

    public const REASON_FORBIDDEN_SELF_TARGET = 'forbidden_self_target';

    public const REASON_REVERT_FAILED = 'git_revert_failed';

    private const LOCK_TIMEOUT_SECONDS = 15.0;

    private const LOCK_POLL_MICROSECONDS = 50_000;

    /** @var (\Closure(string):list<string>)|null */
    private readonly ?\Closure $allowedFilesResolver;

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?string $repoRootOverride = null,
        private readonly ?AtlasMergeGovernorReleaseDecisionLedger $ledgerOverride = null,
        ?\Closure $allowedFilesResolver = null,
    ) {
        $this->allowedFilesResolver = $allowedFilesResolver;
    }

    /**
     * @return array<string, mixed>
     */
    public function revert(string $taskPacketId, bool $dryRun = true): array
    {
        $repo = $this->repoRoot();
        if ($taskPacketId === '') {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_AMBIGUOUS_SHA, ['candidate_count' => 0]);
        }
        if (! is_dir($repo.'/.git')) {
            return $this->refuse($taskPacketId, $dryRun, 'not_a_git_repo');
        }

        $candidates = $this->resolveLandedCommits($repo, $taskPacketId);
        if (count($candidates) !== 1) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_AMBIGUOUS_SHA, ['candidate_count' => count($candidates), 'candidates' => $candidates]);
        }
        $sha = $candidates[0];

        $files = $this->changedFiles($repo, $sha);

        $allowedFiles = $this->resolveAllowedFiles($taskPacketId);
        $outOfScope = array_values(array_diff($files, $allowedFiles));
        if ($outOfScope !== []) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_OUT_OF_SCOPE_FILE, ['sha' => $sha, 'files' => $outOfScope]);
        }

        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        foreach ($files as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return $this->refuse($taskPacketId, $dryRun, self::REASON_FORBIDDEN_SELF_TARGET, ['sha' => $sha, 'path' => $file]);
            }
        }

        $dirty = $this->dirtyFiles($repo, $files);
        if ($dirty !== []) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_DIRTY_WORKING_TREE_FILE, ['sha' => $sha, 'files' => $dirty]);
        }

        if ($dryRun) {
            $result = [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'dry_run' => true,
                'would_revert' => true,
                'sha' => $sha,
                'files' => $files,
            ];
            $this->recordDecision($taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, [], 'low');

            return $result;
        }

        return $this->withCommitLock($repo, function () use ($repo, $taskPacketId, $sha, $files): array {
            $revert = $this->git($repo, ['revert', '--no-edit', $sha]);
            if ($revert['code'] !== 0) {
                $this->recordDecision($taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED, [self::REASON_REVERT_FAILED], 'high');

                return [
                    'schema' => self::SCHEMA,
                    'task_packet_id' => $taskPacketId,
                    'dry_run' => false,
                    'reverted' => false,
                    'refused' => true,
                    'reason' => self::REASON_REVERT_FAILED,
                    'sha' => $sha,
                    'files' => $files,
                    'stderr' => $revert['err'],
                ];
            }

            $revertSha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);
            $this->recordDecision($taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, [], 'medium');

            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'dry_run' => false,
                'reverted' => true,
                'sha' => $sha,
                'revert_sha' => $revertSha,
                'files' => $files,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function refuse(string $taskPacketId, bool $dryRun, string $reason, array $extra = []): array
    {
        $sha = (string) ($extra['sha'] ?? '');
        $files = (array) ($extra['files'] ?? []);
        $this->recordDecision($taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED, [$reason], 'high');

        return array_merge([
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'dry_run' => $dryRun,
            'refused' => true,
            'reason' => $reason,
        ], $extra);
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $reasons
     */
    private function recordDecision(string $taskPacketId, string $sha, array $files, string $decision, array $reasons, string $riskLevel): void
    {
        $sortedFiles = $files;
        sort($sortedFiles, SORT_STRING);

        $ledger = $this->ledger();
        try {
            $ledger->append([
                'task_packet_id' => $taskPacketId !== '' ? $taskPacketId : 'unknown',
                'candidate_hash' => hash('sha256', 'revert:'.$taskPacketId.':'.$sha),
                'decision' => $decision,
                'reasons' => $reasons,
                'risk_level' => $riskLevel,
                'verification_hash' => hash('sha256', $sha !== '' ? $sha : 'unresolved:'.$taskPacketId),
                'rollback_hash' => hash('sha256', 'revert_of:'.$sha),
                'changed_files_hash' => hash('sha256', implode(',', $sortedFiles)),
                'project_lane' => ['project_id' => 'atlas-server'],
                'decided_at' => date(DATE_ATOM),
            ]);
        } catch (Throwable) {
            // Receipt-writing is best-effort audit trail; a ledger failure must never
            // block the fail-closed safety decision already made above.
        }
    }

    private function ledger(): AtlasMergeGovernorReleaseDecisionLedger
    {
        return $this->ledgerOverride ?? new AtlasMergeGovernorReleaseDecisionLedger(
            storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl'),
        );
    }

    /** @return list<string> */
    private function resolveAllowedFiles(string $taskPacketId): array
    {
        if ($this->allowedFilesResolver !== null) {
            return array_values(array_map('strval', ($this->allowedFilesResolver)($taskPacketId)));
        }

        $record = AtlasTaskServingStack::queueRepo()->get($taskPacketId);
        if ($record === null) {
            return [];
        }

        $allowed = data_get($record, 'task_packet.normalized_scope.allowed_files')
            ?? data_get($record, 'task_packet.allowed_files')
            ?? data_get($record, 'allowed_files')
            ?? [];

        return array_values(array_map('strval', (array) $allowed));
    }

    /**
     * Scans `git log` for commits whose message carries the EXACT marker line
     * {@see AtlasTaskScopedCommitter::commitMessage()} writes: "Atlas-Task: <id>". Exact
     * line matching (not substring) avoids a "task-1" prefix colliding with "task-10".
     *
     * @return list<string>
     */
    private function resolveLandedCommits(string $repo, string $taskPacketId): array
    {
        $marker = 'Atlas-Task: '.$taskPacketId;
        $log = $this->git($repo, ['log', '--all', '--format=%H%x1e%B%x1d']);
        $out = (string) $log['out'];
        if ($out === '') {
            return [];
        }

        $shas = [];
        foreach (explode("\x1d", $out) as $entry) {
            $entry = trim($entry, "\n");
            if ($entry === '') {
                continue;
            }
            [$sha, $body] = array_pad(explode("\x1e", $entry, 2), 2, '');
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                if (trim($line) === $marker) {
                    $shas[] = $sha;
                    break;
                }
            }
        }

        return array_values(array_unique($shas));
    }

    /** @return list<string> */
    private function changedFiles(string $repo, string $sha): array
    {
        $result = $this->git($repo, ['diff-tree', '--no-commit-id', '--name-only', '-r', $sha]);
        $files = array_values(array_filter(array_map('trim', explode("\n", (string) $result['out'])), static fn (string $f): bool => $f !== ''));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function dirtyFiles(string $repo, array $files): array
    {
        if ($files === []) {
            return [];
        }
        $result = $this->git($repo, array_merge(['status', '--porcelain', '--'], $files));
        $out = (string) $result['out'];
        if (trim($out) === '') {
            return [];
        }
        $dirty = [];
        // Do NOT trim $out before splitting: porcelain's 2-char status code is followed by a
        // single leading space that a whole-string trim() would eat, shifting the substr(3)
        // offset and truncating the first character of the path.
        foreach (explode("\n", $out) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $dirty[] = trim(substr($line, 3));
        }

        return array_values(array_unique($dirty));
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCommitLock(string $repo, callable $callback): array
    {
        $lockPath = $repo.'/'.AtlasTaskScopedCommitter::LOCK_REL;
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return ['schema' => self::SCHEMA, 'reverted' => false, 'refused' => true, 'reason' => 'lock_open_failed'];
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        try {
            while (true) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    return ['schema' => self::SCHEMA, 'reverted' => false, 'refused' => true, 'reason' => 'commit_lock_contended'];
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{code:int, out:string, err:string}
     */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    private function repoRoot(): string
    {
        return $this->repoRootOverride ?? base_path();
    }
}
