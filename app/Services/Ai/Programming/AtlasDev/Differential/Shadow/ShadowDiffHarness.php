<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;


/**
 * E4 -- Injected collaborator that executes the OLD vs NEW implementation of
 * a pure function on the same probe inputs and returns the captured outputs.
 *
 * The harness is the SEAM between the deterministic, model-irrelevant
 * {@see ShadowDiffService} (which decides WHAT to compare) and the actual
 * execution substrate (HOW old vs new run). Production wires a subprocess
 * harness ({@see PhpSubprocessShadowDiffHarness}); tests inject a fake that
 * returns scripted outputs so the pipeline is exercised without spawning
 * real PHP subprocesses.
 *
 * Contract (mirrors the anti-gaming pétreos):
 *   - The harness MUST be deterministic for the same (old, new, inputs).
 *   - On ANY execution failure (parse error, runtime error, timeout) the
 *     harness returns a {@see ShadowDiffHarnessResult::failed()} so the
 *     service can skip the symbol with a reason (never fabricate a divergence
 *     and never crash the pipeline — VAL-E4-011 honest ceiling).
 *   - The harness MUST NOT mutate external state (it executes the function
 *     bodies in isolation). The {@see PureFunctionDetector} already filters
 *     impure bodies before the harness is invoked, so a body that reaches
 *     the harness has been conservatively classified pure; the harness is a
 *     defense-in-depth layer.
 *
 * The probe inputs are argument tuples: each entry is a list of positional
 * arguments to pass to both function versions. The harness calls both old
 * and new with each tuple in turn and captures the return value (serialized
 * to a string for comparison). Divergence is decided by the SERVICE, not the
 * harness — the harness only reports the captured outputs.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
interface ShadowDiffHarness
{
    /**
     * Execute the old and new function bodies against the same probe inputs.
     *
     * Each function body is a self-contained PHP source fragment defining a
     * single free function (the {@see ShadowDiffService} extracts the body
     * and wraps it so it executes in isolation). The harness MAY execute
     * them in a sandboxed subprocess, via eval, or by any deterministic
     * means — the only contract is that the same inputs produce the same
     * outputs for the same source.
     *
     * @param  string  $oldBodySource  the OLD function body, self-contained (defines a callable).
     * @param  string  $newBodySource  the NEW function body, self-contained (defines a callable).
     * @param  list<list<mixed>>  $probeInputs  argument tuples; each tuple is a list of positional args.
     * @return ShadowDiffHarnessResult the captured old/new outputs, or a failure result.
     */
    public function shadowDiff(string $oldBodySource, string $newBodySource, array $probeInputs): ShadowDiffHarnessResult;
}
