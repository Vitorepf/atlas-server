<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The HELD-OUT delta certifier — the anti-gaming moat of the optimize lane.
 *
 * The optimizer (the proposer that hill-climbs a metric) is ONLY ever allowed to see and run the
 * DEV (train) split of a held-out acceptance contract. This certifier proves the gain on the FROZEN
 * TEST (held-out) split that the optimizer never touched, candidate-vs-baseline, fail-CLOSED — exactly
 * the train/test discipline that stops a candidate from overfitting the very command it optimized.
 *
 * By construction it can never raise conversion dishonestly:
 *   - It reads the held-out 'test' command ONLY (never the dev split the optimizer climbed).
 *   - The scalar is measured by RUNNING the command and regex-extracting — never a model claim.
 *   - The delta is candidate-vs-baseline measured IN THE SAME workspace via git stash: measure the
 *     candidate (diff live), stash to the committed baseline, measure baseline, restore. So the gain
 *     is proven against the candidate's OWN baseline, not an ambient or self-reported number.
 *   - FAIL-CLOSED on every doubt: a non-finite metric, a stash/restore that cannot isolate the
 *     baseline, or a zero delta all yield improved=false with a precise reason.
 *   - The worktree is ALWAYS restored (stash pop) in a finally, even when measuring throws.
 *
 * Feature-off is BYTE-IDENTICAL: when the acceptance has no held-out block (harness not armed) the
 * certifier returns {armed:false} and gates NOTHING — the loop behaves exactly as before.
 */
final class AtlasLoopHeldOutDeltaCertifier
{
    /** @var Closure(array<int,string>,string):array{stdout:string,exit:int} */
    private Closure $git;

    /**
     * @param  (Closure(array<int,string>,string):array{stdout:string,exit:int})|null  $git  fn(args, workspace)
     */
    public function __construct(private AtlasLoopMetricHarness $harness, ?Closure $git = null)
    {
        $this->git = $git ?? static function (array $args, string $workspace): array {
            $process = new Process($args, $workspace, null, null, 60.0);
            $process->run();

            return [
                'stdout' => $process->getOutput(),
                'exit' => (int) $process->getExitCode(),
            ];
        };
    }

    /**
     * Certify a candidate moved the metric on the HELD-OUT (test) split, candidate-vs-baseline.
     *
     * @param  array<string,mixed>  $acceptance
     * @return array{armed:bool, baseline:float, candidate:float, improvement:float, improved:bool, reason:?string}
     */
    public function certifyHeldOut(string $workspace, array $acceptance): array
    {
        // Feature-off: no held-out block => byte-identical, gate NOTHING.
        if (! AtlasLoopMetricHarness::isArmed($acceptance)) {
            return $this->verdict(false, 0.0, 0.0, 0.0, false, null);
        }

        $heldOut = is_array($acceptance['held_out'] ?? null) ? $acceptance['held_out'] : [];
        $kind = (string) ($heldOut['metric_kind'] ?? 'maximize');

        // 1. CANDIDATE first — the diff is live in the working tree right now. Always the TEST split.
        //    The harness evaluates the held_out block, never the dev split, so the optimizer's train
        //    command can never decide certification.
        $candidateEval = $this->harness->evaluate($workspace, $heldOut, 'test');
        $candidate = (float) ($candidateEval['metric'] ?? NAN);
        if (! $this->finite($candidateEval, $candidate)) {
            return $this->verdict(true, 0.0, $candidate, 0.0, false, 'held_out:metric_non_finite');
        }

        // 2. Stash the candidate diff to isolate the committed baseline, measure baseline, restore.
        //    ALWAYS attempt to pop in the finally so the worktree is never left mutated. We track the
        //    push attempt (not just a confirmed stash) so a push that returned 0 but captured nothing
        //    still triggers a best-effort restore — the worktree must never be left disturbed.
        $pushIssued = false;
        try {
            $push = ($this->git)(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace);
            $pushIssued = ($push['exit'] ?? 1) === 0;
            // The push must have actually captured a diff — a no-op candidate (nothing to stash) cannot
            // be isolated from its baseline, so the delta is unprovable. Fail closed.
            if (! $pushIssued || ! $this->stashCreated($workspace)) {
                return $this->verdict(true, 0.0, $candidate, 0.0, false, 'held_out:stash_failed');
            }

            $baselineEval = $this->harness->evaluate($workspace, $heldOut, 'test');
            $baseline = (float) ($baselineEval['metric'] ?? NAN);
            if (! $this->finite($baselineEval, $baseline)) {
                return $this->verdict(true, $baseline, $candidate, 0.0, false, 'held_out:metric_non_finite');
            }

            // 3. No delta at all is not an improvement — and a suspicious sign the metric is inert on
            //    the held-out split. Fail closed (strictly: improved = improvement > 0).
            if ($baseline === $candidate) {
                return $this->verdict(true, $baseline, $candidate, 0.0, false, 'held_out:no_delta');
            }

            $improvement = $this->harness->improvement($baseline, $candidate, $kind);
            $improved = $improvement > 0.0;

            return $this->verdict(true, $baseline, $candidate, $improvement, $improved, null);
        } catch (Throwable $e) {
            return $this->verdict(true, 0.0, $candidate, 0.0, false, 'held_out:stash_failed');
        } finally {
            // GUARANTEE restore: always attempt the pop once a push was issued (even on throw / early
            // return inside the try, and even when the push captured nothing). Best-effort and harmless
            // when no stash exists, so the worktree is never left mutated by this certifier.
            if ($pushIssued) {
                try {
                    ($this->git)(['git', 'stash', 'pop', '--quiet'], $workspace);
                } catch (Throwable) {
                    // best-effort restore; nothing more we can do here.
                }
            }
        }
    }

    /**
     * A measured metric is usable only when the harness reports it finite AND PHP agrees it is a
     * real, finite float (defends against NAN/INF leaking past the harness flag).
     *
     * @param  array<string,mixed>  $eval
     */
    private function finite(array $eval, float $metric): bool
    {
        return (bool) ($eval['metric_finite'] ?? false) && is_finite($metric);
    }

    /** True when a stash entry exists (the push actually captured the candidate diff). */
    private function stashCreated(string $workspace): bool
    {
        $list = ($this->git)(['git', 'stash', 'list'], $workspace);

        return trim((string) ($list['stdout'] ?? '')) !== '';
    }

    /**
     * @return array{armed:bool, baseline:float, candidate:float, improvement:float, improved:bool, reason:?string}
     */
    private function verdict(bool $armed, float $baseline, float $candidate, float $improvement, bool $improved, ?string $reason): array
    {
        return [
            'armed' => $armed,
            'baseline' => $baseline,
            'candidate' => $candidate,
            'improvement' => $improvement,
            'improved' => $improved,
            'reason' => $reason,
        ];
    }
}
