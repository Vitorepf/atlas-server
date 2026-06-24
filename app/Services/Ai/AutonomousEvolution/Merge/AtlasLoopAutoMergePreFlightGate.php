<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * WAVE-14 · AUTO-MERGE HARDENING — the FAIL-CLOSED pre-flight chokepoint that runs BEFORE every auto-merge
 * attempt. It protects shared `main` from being overwritten by a STALE loop branch: per the loop-cycle git
 * contract (commit → merge-to-main → new-branch-from-FRESH-main), a proposal records the base SHA it branched
 * from; if `main` has MOVED since then, merging the stale branch could clobber work that landed in between.
 *
 * The gate (a) takes the proposal's recorded base_sha, (b) re-resolves the CURRENT main HEAD via real git
 * inside an EXCLUSIVE flock (so two concurrent workers cannot both pass for the same repo), (c) compares with
 * FACTS only — equal or not, no score, no proxy — and (d) returns a structured refusal with reason=main_moved
 * when divergent. A worker that cannot acquire the lock does NOT pass (reason=preflight_lock_busy).
 */
final class AtlasLoopAutoMergePreFlightGate
{
    public const SCHEMA = 'atlas.loop.automerge_preflight.v1';

    private const LOCK_RELATIVE = '.git/atlas-automerge-preflight.lock';

    /**
     * @return array{schema_version:string, allow:bool, base_sha:string, head_sha:?string, equal:bool, reason:?string}
     */
    public function check(string $baseSha, string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $baseSha = trim($baseSha);

        $fp = @fopen($this->lockPath($repoRoot), 'c');
        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_resource($fp)) {
                @fclose($fp);
            }

            // Another worker holds the pre-flight lock for this repo — fail-closed (never two passes at once).
            return $this->result(false, $baseSha, null, false, 'preflight_lock_busy');
        }

        try {
            $head = $this->resolveMainHead($repoRoot);
            if ($head === null) {
                return $this->result(false, $baseSha, null, false, 'cannot_resolve_main_head');
            }
            $equal = $baseSha !== '' && hash_equals($head, $baseSha);

            return $this->result($equal, $baseSha, $head, $equal, $equal ? null : 'main_moved');
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /** The exclusive pre-flight lock path for a repo (exposed so callers/tests serialize on the same file). */
    public function lockPath(string $repoRoot): string
    {
        return rtrim($repoRoot, '/').'/'.self::LOCK_RELATIVE;
    }

    /**
     * @return array{schema_version:string, allow:bool, base_sha:string, head_sha:?string, equal:bool, reason:?string}
     */
    private function result(bool $allow, string $baseSha, ?string $headSha, bool $equal, ?string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'allow' => $allow,
            'base_sha' => $baseSha,
            'head_sha' => $headSha,
            'equal' => $equal,
            'reason' => $reason,
        ];
    }

    private function resolveMainHead(string $repoRoot): ?string
    {
        foreach (['main', 'HEAD'] as $ref) {
            $sha = $this->git($repoRoot, ['rev-parse', $ref]);
            if ($sha !== null) {
                return $sha;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $repoRoot, array $args): ?string
    {
        try {
            $process = new Process(array_merge(['git'], $args), $repoRoot, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $out = trim($process->getOutput());

            return $out !== '' ? $out : null;
        } catch (Throwable) {
            return null;
        }
    }
}
