<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use Symfony\Component\Process\Process;

/**
 * LOOP-OS · FASE 3 · SLICE 4.5 — the BATTERY RUNNER: runs a CANDIDATE judge's actual bytes against the
 * frozen battery in an isolated subprocess, scoring ONLY OS exit codes (pétreo / FORBIDDEN under Constitution/).
 *
 * The R3 keystone: a candidate that edits the cert chain must be PROVEN to still REFUTE every known-bad and
 * CERTIFY every known-good — and that proof is worthless unless it runs the candidate's EDITED bytes, not the
 * live autoloaded judge. So {@see run()} materializes a clone-local worktree (via the FrameworkMaterializer's
 * real-copied vendor/composer/autoload, which asserts App\ resolves INSIDE the worktree — never the symlinking
 * materializeFull that loads the LIVE judge), applies the candidate diff STRICTLY (no `--reject` salvage that
 * would diverge the scored tree from the diff), and drives the FROZEN probe entrypoint as a subprocess. The
 * runner trusts NOTHING the probe says except its exit code (§10.5) — every non-zero, and a null/unknown exit,
 * is a REJECT (stricter than FrozenJudge's `?? 1`). Fail-closed is the whole point: a crash, a timeout, or a
 * tree that could not be proven to hold the candidate bytes all REJECT.
 *
 * This class is the MECHANISM; it never relaxes a guard and is not wired to any live cert path here (that
 * atomic transition is Slice 4). The §3.6 sentinel suite (frozen — tests/Feature/Loop/Constitution/) is the
 * build-time discharge that admits a property_gated self-edit only when this runner provably catches a blinder.
 */
final class AtlasLoopBatteryRunner
{
    /** §10.5 probe exit-code surface — the ONLY channel the runner trusts. */
    public const EXIT_PASS = 0;

    public const EXIT_SURVIVING_BAD = 10;

    public const EXIT_GOOD_NOT_CERTIFIED = 20;

    public const EXIT_ROBUSTNESS_VIOLATION = 30;

    public const EXIT_PROBE_CRASHED = 64;

    public const EXIT_PROBE_TIMEOUT = 65;

    public const EXIT_BYTES_NOT_PROVEN = 66;

    /** The frozen probe entrypoint (driven as a subprocess so it loads the CANDIDATE's bytes). */
    public const PROBE_REL = 'app/Services/Ai/AutonomousEvolution/Constitution/probe/atlas-loop-cert-probe.php';

    /**
     * Map a probe exit code to a verdict. PASS iff EXACTLY 0; EVERY other code — including null/unknown — is a
     * REJECT with a specific reason. This is the fail-closed contract the sentinel asserts per-code.
     *
     * @return array{pass:bool, reason:string, exit:?int}
     */
    public function score(?int $exit): array
    {
        $reason = match ($exit) {
            self::EXIT_PASS => 'pass',
            self::EXIT_SURVIVING_BAD => 'surviving_bad',
            self::EXIT_GOOD_NOT_CERTIFIED => 'good_not_certified',
            self::EXIT_ROBUSTNESS_VIOLATION => 'robustness_violation',
            self::EXIT_PROBE_CRASHED => 'probe_crashed',
            self::EXIT_PROBE_TIMEOUT => 'probe_timeout',
            self::EXIT_BYTES_NOT_PROVEN => 'bytes_not_proven',
            default => 'unknown_exit',
        };

        return ['pass' => $exit === self::EXIT_PASS, 'reason' => $reason, 'exit' => $exit];
    }

    /**
     * Apply a unified diff to a worktree STRICTLY — `git apply --check` then `git apply`, NEVER `--reject`. A
     * partial/rejectable apply would drop hunks and diverge the scored tree from the candidate's actual diff
     * (the closure-Merkle would not catch a dropped hunk in a non-closure file), so any non-clean apply is a
     * hard fail-closed false. Empty diff = nothing to apply = true (a no-op candidate is scored as-is).
     */
    public function strictApply(string $worktree, string $diffText): bool
    {
        if (trim($diffText) === '') {
            return true;
        }
        $patch = $worktree.'/.atlas-constitution-candidate.patch';
        file_put_contents($patch, $diffText);
        try {
            if (! $this->git($worktree, ['apply', '--check', '--whitespace=nowarn', basename($patch)])) {
                return false; // would not apply cleanly ⇒ fail-closed (no --reject salvage)
            }

            return $this->git($worktree, ['apply', '--whitespace=nowarn', basename($patch)]);
        } finally {
            @unlink($patch);
        }
    }

    /**
     * Drive the frozen probe over a prepared CANDIDATE worktree (already materialized + candidate-applied) and
     * the battery directory, scoring only the exit code. A probe that exceeds the timeout is REJECTED as
     * probe_timeout (fail-closed), and a worktree missing the probe entrypoint is bytes_not_proven.
     *
     * @return array{pass:bool, reason:string, exit:?int}
     */
    public function probe(string $worktree, string $batteryDir, float $timeoutSeconds = 120.0): array
    {
        $probe = $worktree.'/'.self::PROBE_REL;
        if (! is_file($probe)) {
            return $this->score(self::EXIT_BYTES_NOT_PROVEN); // the candidate tree does not even carry the probe
        }
        $proc = new Process([PHP_BINARY, $probe, $worktree, $batteryDir], $worktree, null, null, $timeoutSeconds);
        try {
            $proc->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            return $this->score(self::EXIT_PROBE_TIMEOUT);
        } catch (\Throwable) {
            return $this->score(self::EXIT_PROBE_CRASHED);
        }

        return $this->score($proc->getExitCode());
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $cwd, array $args): bool
    {
        $p = new Process(array_merge(['git'], $args), $cwd, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }
}
