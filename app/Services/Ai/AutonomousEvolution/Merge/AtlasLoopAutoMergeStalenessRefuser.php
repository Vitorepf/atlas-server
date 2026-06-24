<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * WAVE-14 · AUTO-MERGE HARDENING — the CHEAPEST fail-fast checkpoint, runs BEFORE PreFlightGate /
 * ConflictDetector / ReverseAuditor. Per the loop-cycle-git-contract anti-579 staleness preflight, a base SHA
 * more than N commits behind current `main` is REFUSED. The commits-behind count is a real FACT computed via
 * `git rev-list --count base_sha..main`, never a score.
 *
 * Defaults: N = config('atlas.loop.automerge.staleness_max_commits_behind', 25). A base equal to HEAD is
 * commits_behind=0 ⇒ allow. Unresolvable refs / git failure ⇒ fail-CLOSED.
 */
final class AtlasLoopAutoMergeStalenessRefuser
{
    public const SCHEMA = 'atlas.loop.automerge.staleness.v1';

    public const REASON_STALE_BASE = 'stale_base';

    public const REASON_UNRESOLVABLE = 'unresolvable_ref';

    public const DEFAULT_MAX_BEHIND = 25;

    public function __construct(private readonly ?int $maxCommitsBehindFromConfig = null) {}

    /**
     * @return array{schema:string, allow:bool, base_sha:string, head_sha:?string, commits_behind:int, max_commits_behind:int, reason:?string}
     */
    public function check(string $baseSha, string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $baseSha = trim($baseSha);
        $max = $this->resolveMaxBehind();

        $head = $this->resolveHead($repoRoot);
        if ($head === null || $baseSha === '') {
            return $this->result(false, $baseSha, $head, 0, $max, self::REASON_UNRESOLVABLE);
        }

        // commits_behind = how many commits are on main but NOT on base.
        $behind = $this->revListCount($repoRoot, $baseSha, $head);
        if ($behind === null) {
            return $this->result(false, $baseSha, $head, 0, $max, self::REASON_UNRESOLVABLE);
        }

        if ($behind > $max) {
            return $this->result(false, $baseSha, $head, $behind, $max, self::REASON_STALE_BASE);
        }

        return $this->result(true, $baseSha, $head, $behind, $max, null);
    }

    private function resolveMaxBehind(): int
    {
        if ($this->maxCommitsBehindFromConfig !== null) {
            return max(0, $this->maxCommitsBehindFromConfig);
        }

        return max(0, (int) config('atlas.loop.automerge.staleness_max_commits_behind', self::DEFAULT_MAX_BEHIND));
    }

    private function resolveHead(string $repoRoot): ?string
    {
        foreach (['main', 'HEAD'] as $ref) {
            $sha = $this->git($repoRoot, ['rev-parse', $ref]);
            if ($sha !== null) {
                return $sha;
            }
        }

        return null;
    }

    private function revListCount(string $repoRoot, string $baseSha, string $head): ?int
    {
        $out = $this->git($repoRoot, ['rev-list', '--count', $baseSha.'..'.$head]);
        if ($out === null || ! ctype_digit($out)) {
            return null;
        }

        return (int) $out;
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $repoRoot, array $args): ?string
    {
        try {
            $p = new Process(array_merge(['git'], $args), $repoRoot, null, null, 30.0);
            $p->run();
            if (! $p->isSuccessful()) {
                return null;
            }
            $o = trim($p->getOutput());

            return $o !== '' ? $o : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{schema:string, allow:bool, base_sha:string, head_sha:?string, commits_behind:int, max_commits_behind:int, reason:?string}
     */
    private function result(bool $allow, string $baseSha, ?string $head, int $behind, int $max, ?string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'allow' => $allow,
            'base_sha' => $baseSha,
            'head_sha' => $head,
            'commits_behind' => $behind,
            'max_commits_behind' => $max,
            'reason' => $reason,
        ];
    }
}
