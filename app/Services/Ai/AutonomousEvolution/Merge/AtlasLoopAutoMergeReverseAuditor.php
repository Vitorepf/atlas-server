<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

use Throwable;

/**
 * WAVE-14 · REVERSE AUDITOR — closes the sibling-ride contamination loophole described by the slice-0 finding:
 * a candidate merge can pass every PRE-merge gate (preflight + conflict detector) and still leave the combined
 * post-merge `main` in a state that fails the moat. This auditor runs IMMEDIATELY AFTER a successful merge,
 * re-proves the certification gate against the POST-merge `main`, and — if the gate regresses — performs a real
 * `git revert -m 1 <merge_sha>` so `main` returns to its pre-merge SHA. The audit emits a structured fact for
 * the cycle git contract receipt: pre_merge_sha, merge_sha, post_merge_sha, verdict, gate_diagnostics.
 *
 * The auditor acquires the SAME exclusive `.git/atlas-automerge-preflight.lock` used by the merge pipeline so
 * the revert cannot interleave with a concurrent worker that just opened pre-flight. Verdicts:
 *
 *   - VERDICT_CONFIRMED   : post-merge gate passed; main is untouched.
 *   - VERDICT_ROLLED_BACK : post-merge gate failed; main was reverted to pre_merge_sha.
 *   - VERDICT_ABSTAIN     : gate prover was not wired (auditor cannot vote on un-measured state).
 *   - VERDICT_REVERT_FAILED: gate failed AND `git revert` did not bring HEAD back to pre_merge_sha — surfaces
 *     a real ops issue (operator intervention required).
 *
 * PURE policy + REAL I/O at the boundary: the gate prover and the git-revert runner are both injectable
 * closures with conservative defaults (gate prover defaults to ABSTAIN, revert runner defaults to a real
 * `git revert`). Tests inject fakes; production wires real ones.
 */
final class AtlasLoopAutoMergeReverseAuditor
{
    public const SCHEMA = 'atlas.loop.automerge_reverse_audit.v1';

    public const VERDICT_CONFIRMED = 'confirmed';

    public const VERDICT_ROLLED_BACK = 'rolled_back';

    public const VERDICT_ABSTAIN = 'abstain';

    public const VERDICT_REVERT_FAILED = 'revert_failed';

    /** @var null|callable(string $repoRoot):array{passed:bool, diagnostics:array<string,mixed>} */
    private $gateProver;

    /** @var null|callable(string $repoRoot, string $mergeSha):bool */
    private $revertRunner;

    /** @var null|callable(string $repoRoot):?string */
    private $headResolver;

    /**
     * @param  null|callable(string):array{passed:bool, diagnostics:array<string,mixed>}  $gateProver
     * @param  null|callable(string,string):bool  $revertRunner
     * @param  null|callable(string):?string  $headResolver
     */
    public function __construct(
        ?callable $gateProver = null,
        ?callable $revertRunner = null,
        ?callable $headResolver = null,
    ) {
        $this->gateProver = $gateProver;
        $this->revertRunner = $revertRunner;
        $this->headResolver = $headResolver;
    }

    /**
     * @return array{
     *     schema_version:string,
     *     verdict:string,
     *     pre_merge_sha:string,
     *     merge_sha:string,
     *     post_merge_sha:?string,
     *     gate_diagnostics:array<string,mixed>
     * }
     */
    public function audit(string $repoRoot, string $preMergeSha, string $mergeSha): array
    {
        if (! is_callable($this->gateProver)) {
            return $this->record(self::VERDICT_ABSTAIN, $preMergeSha, $mergeSha, $mergeSha, ['reason' => 'gate_prover_not_wired']);
        }

        $lockPath = rtrim($repoRoot, '/').'/.git/atlas-automerge-preflight.lock';
        $fp = @fopen($lockPath, 'c');
        if ($fp === false || ! flock($fp, LOCK_EX)) {
            if (is_resource($fp)) {
                @fclose($fp);
            }

            return $this->record(self::VERDICT_ABSTAIN, $preMergeSha, $mergeSha, $mergeSha, ['reason' => 'reverse_audit_lock_busy']);
        }

        try {
            try {
                $gateResult = ($this->gateProver)($repoRoot);
            } catch (Throwable $e) {
                return $this->record(self::VERDICT_ABSTAIN, $preMergeSha, $mergeSha, $mergeSha, ['reason' => 'gate_prover_threw', 'message' => $e->getMessage()]);
            }
            $passed = (bool) ($gateResult['passed'] ?? false);
            $diagnostics = is_array($gateResult['diagnostics'] ?? null) ? $gateResult['diagnostics'] : [];

            if ($passed) {
                return $this->record(self::VERDICT_CONFIRMED, $preMergeSha, $mergeSha, $mergeSha, $diagnostics);
            }

            // Gate regressed — revert the merge so main returns to pre_merge_sha.
            $reverted = $this->runRevert($repoRoot, $mergeSha);
            $postSha = $this->resolveHead($repoRoot);

            if ($reverted && $postSha === $preMergeSha) {
                return $this->record(self::VERDICT_ROLLED_BACK, $preMergeSha, $mergeSha, $postSha, $diagnostics);
            }

            return $this->record(self::VERDICT_REVERT_FAILED, $preMergeSha, $mergeSha, $postSha, $diagnostics + ['revert_ran' => $reverted]);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    private function runRevert(string $repoRoot, string $mergeSha): bool
    {
        $runner = $this->revertRunner;
        if (is_callable($runner)) {
            try {
                return (bool) $runner($repoRoot, $mergeSha);
            } catch (Throwable) {
                return false;
            }
        }

        $cmd = sprintf(
            'cd %s && git revert --no-edit -m 1 %s > /dev/null 2>&1; echo $?',
            escapeshellarg($repoRoot),
            escapeshellarg($mergeSha),
        );
        $exit = (int) trim((string) shell_exec($cmd));

        return $exit === 0;
    }

    private function resolveHead(string $repoRoot): ?string
    {
        $resolver = $this->headResolver;
        if (is_callable($resolver)) {
            try {
                $sha = $resolver($repoRoot);

                return $sha === null ? null : trim((string) $sha);
            } catch (Throwable) {
                return null;
            }
        }

        $cmd = sprintf('cd %s && git rev-parse HEAD 2>/dev/null', escapeshellarg($repoRoot));
        $sha = trim((string) shell_exec($cmd));

        return $sha === '' ? null : $sha;
    }

    /**
     * @param  array<string,mixed>  $diagnostics
     * @return array{schema_version:string, verdict:string, pre_merge_sha:string, merge_sha:string, post_merge_sha:?string, gate_diagnostics:array<string,mixed>}
     */
    private function record(string $verdict, string $preMergeSha, string $mergeSha, ?string $postMergeSha, array $diagnostics): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'pre_merge_sha' => $preMergeSha,
            'merge_sha' => $mergeSha,
            'post_merge_sha' => $postMergeSha,
            'gate_diagnostics' => $diagnostics,
        ];
    }
}
