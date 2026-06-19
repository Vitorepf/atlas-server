<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §11.4 — BUG-FIX REPRODUCTION LANE: makes bug-fixing a FIRST-CLASS work-type alongside
 * refactor and feature. Most of the loop's discovery supply frames code-shaped objectives
 * (reduce complexity, add a behavior). This lane converts a raw FAILURE-SIGNATURE — the
 * shape of something that is already broken — into a REPRODUCTION-RED objective so the
 * SAME implement→cert pipeline that proves a feature can also prove a fix.
 *
 * The contract is the inversion of the refactor lane: a refactor keeps a frozen sibling test
 * GREEN (revert_recheck=false, behavior-PRESERVING); a bug-fix must first make the reproduced
 * failure go RED against the pre-fix tree, then GREEN after the fix (red_required=true,
 * behavior-CHANGING). The RED bar IS the reproduced failure: the acceptance command is the
 * failing test, and red_required:true means the lane requires that command is VERIFIED RED on
 * the pre-fix tree before the fix is allowed to count — a fix for a bug that was never
 * reproducibly red proves nothing.
 *
 * DETERMINISTIC + provider-free + fail-closed. This lane is the deterministic HALF of the
 * job: given a signature that already carries a runnable failing-test handle (target_path +
 * test_path / explicit command), it shapes the objective and the RED-required acceptance.
 * The OTHER half — SYNTHESISING a faithful reproduction test from a raw log/stack when no
 * runnable handle exists — is model-bound (it requires understanding the failure semantically)
 * and is explicitly OUT OF SCOPE here: such a signature yields null rather than a fabricated
 * repro, because a repro the lane invented out of a log is not a proof, it is a guess.
 *
 * Fail-closed rule: NO target_path (and no runnable handle to anchor a fix) ⇒ null. The lane
 * never fabricates a reproduction for a signature it cannot honestly anchor.
 */
final class AtlasLoopBugReproductionLane
{
    /** Advisory work-shape tag — never gates the cert; mirrors the refactor lane's objective_kind. */
    public const SHAPE = 'bug_fix';

    /**
     * Convert a failure-signature into a reproduce-then-fix objective, or null when the
     * signature lacks an anchor the loop could honestly fix against.
     *
     * Input (all keys optional except the anchor):
     *   - target_path:      repo-relative path of the file believed to contain the bug (the anchor)
     *   - test_path:        repo-relative path of the test that reproduces the failure (preferred handle)
     *   - command:          an explicit failing test command (alternative handle, e.g. a filtered run)
     *   - failing_assertion:the assertion / case name that fails (sharpens the objective text)
     *   - message:          the failure message (human context for the fix)
     *   - stack:            the stack trace (human context for the fix)
     *
     * @param  array<string,mixed>  $failureSignature
     * @return array{
     *     objective:string,
     *     target_path:string,
     *     acceptance:array{commands:list<string>, red_required:true},
     *     shape:string
     * }|null
     */
    public function toReproductionObjective(array $failureSignature): ?array
    {
        $targetPath = $this->cleanPath($failureSignature['target_path'] ?? null);
        if ($targetPath === null) {
            // No anchor file ⇒ nothing to honestly fix against. Fail-closed: never fabricate.
            return null;
        }

        // The RED bar must be a CONCRETE, runnable failing-test command. Prefer an explicit
        // command; else derive one from a test_path. Without either there is no runnable handle
        // to verify RED against the pre-fix tree, so synthesising one from a raw log/stack is the
        // model-bound part we refuse to fake here.
        $command = $this->reproductionCommand($failureSignature);
        if ($command === null) {
            return null;
        }

        return [
            'objective' => $this->objectiveText($targetPath, $failureSignature),
            'target_path' => $targetPath,
            'acceptance' => [
                // The failing test command that must go RED first (on the pre-fix tree) then
                // GREEN after the fix. This is the SAME acceptance.commands shape the normal
                // implement→cert path consumes.
                'commands' => [$command],
                // The lane requires the command is VERIFIED RED against the pre-fix tree before
                // the fix counts: a bug-fix whose repro was never red proves nothing.
                'red_required' => true,
            ],
            'shape' => self::SHAPE,
        ];
    }

    /**
     * Derive the concrete failing-test command, or null when no runnable handle exists.
     * An explicit `command` wins; otherwise a `test_path` becomes a phpunit run for that file.
     */
    private function reproductionCommand(array $sig): ?string
    {
        $explicit = $this->cleanString($sig['command'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $testPath = $this->cleanPath($sig['test_path'] ?? null);
        if ($testPath !== null) {
            return './vendor/bin/phpunit '.$testPath;
        }

        return null;
    }

    /**
     * A clear "reproduce then fix" instruction. Stable per (target, assertion) so the same
     * signature dedupes to the same objective and does not bloat the queue across cycles.
     */
    private function objectiveText(string $targetPath, array $sig): string
    {
        $assertion = $this->cleanString($sig['failing_assertion'] ?? null);
        $message = $this->cleanString($sig['message'] ?? null);

        $what = $assertion !== null
            ? 'the failing case «'.$assertion.'»'
            : 'the reported failure';

        $text = 'Reproduce '.$what.' in '.$targetPath.' as a RED test FIRST, '
            .'then fix '.$targetPath.' so that reproduction test goes GREEN '
            .'(behavior-changing bug fix — the reproduced failure is the acceptance bar).';

        if ($message !== null) {
            $text .= ' Reported failure: '.$message;
        }

        return $text;
    }

    /** Trim + reject empty/non-string paths; normalize a leading slash off so paths are repo-relative. */
    private function cleanPath(mixed $value): ?string
    {
        $clean = $this->cleanString($value);

        return $clean === null ? null : ltrim($clean, '/');
    }

    /** Trim a value to a non-empty string, or null. */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
