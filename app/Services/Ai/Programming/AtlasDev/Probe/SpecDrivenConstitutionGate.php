<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;

/**
 * E6 — Spec-Driven Constitution Gate.
 *
 * Validates the diff's touched files against the task's
 * {@see MiniProgrammingSpec} (the task's constitution): acceptance criteria
 * honored, non-goals respected, forbidden files respected. This is the
 * SEMANTIC scope check, distinct from the ScopeGuard (which mechanically
 * enforces the task contract's allowed_files). E6 catches out-of-spec
 * behavior the ScopeGuard does not — e.g. a diff that touches a
 * forbidden_file declared in the spec while staying within the contract's
 * allowed_files mechanically (VAL-M2-024).
 *
 * The gate is DETERMINISTIC and model-irrelevant: it inspects the spec's
 * declared boundaries (forbiddenFiles, nonGoals, allowedFiles, expectedFiles,
 * acceptanceCriteria) against the touched file paths from the scope receipt.
 * It does NOT attempt line-level semantic analysis of the diff content —
 * that is beyond a deterministic gate. The honest ceiling
 * (VAL-M2-033) is respected: when the spec cannot be read or the evaluation
 * errors, the gate surfaces an unevaluable verdict (never silently green).
 *
 * Checks (in order, all deterministic):
 *
 *   1. NO-OP (VAL-M2-034): if the spec has no acceptance criteria, no
 *      non-goals, no forbidden files, and no expected behavior, there is no
 *      constitution to validate. The gate returns a no-op (no flag, no
 *      trip). A spec-less task is never false-flagged.
 *
 *   2. FORBIDDEN FILES (VAL-M2-024): if any touched file path matches a
 *      forbidden file declared in the spec (exact, glob, or directory-prefix
 *      match), the gate trips with spec_constitution_violation. This is the
 *      semantic forbidden-file check: the ScopeGuard enforces the contract's
 *      allowed_files; E6 enforces the spec's forbidden_files — they can
 *      differ, and a file can be mechanically allowed but semantically
 *      forbidden.
 *
 *   3. NON-GOALS (VAL-M2-024): for each non-goal string, the gate extracts
 *      file-path-like tokens (substrings that look like file paths) and
 *      checks if any touched file matches. A diff that implements a
 *      non-goal trips the gate even when the file is mechanically within
 *      allowed_files. Non-goals without extractable file paths are not
 *      matched (the gate cannot deterministically evaluate free-text
 *      behavioral non-goals — it catches the file-path-referencing subset).
 *
 *   4. OUT-OF-SPEC SCOPE CREEP (VAL-M2-021): if the spec declares
 *      acceptance criteria AND has a non-empty allowed/expected file scope,
 *      any touched NON-TEST file that is NOT in the spec's allowed/expected
 *      scope trips the gate. This is the "diff introduces behavior not
 *      justified by any acceptance criterion" check: the diff touches a
 *      file the spec never authorized. Test files (paths under tests/) are
 *      excluded — tests are verification, not behavior. When the spec's
 *      file scope is empty, the check is skipped (no scope to compare
 *      against — never a false fail).
 *
 * Channels (no third way, no silent green — routed by the executor):
 *   - off      => the gate is not invoked (byte-identical to pre-E6).
 *   - advisory => honesty flag only (PASSED -> needs_review downgrade).
 *   - hard     => STATUS_FAILED gate channel (completion `failed`).
 *
 * Honest ceiling (VAL-M2-033): when the spec is unreadable/corrupt or the
 * evaluation throws, the gate returns an unevaluable verdict. The executor
 * routes this through the same channels with the spec_unevaluable flag:
 * advisory => needs_review; hard => failed. Never a silent pass, never a
 * crash.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, E6).
 */
final class SpecDrivenConstitutionGate
{
    /**
     * The honesty-flag name appended when the diff violates the
     * spec/constitution (forbidden file, non-goal, or out-of-spec scope
     * creep). Shared so the executor and tests reference the canonical
     * string.
     */
    public const FLAG_SPEC_CONSTITUTION_VIOLATION = 'spec_constitution_violation';

    /**
     * The honesty-flag name appended when the spec-constitution check is
     * unevaluable (spec unreadable/corrupt or evaluation errored). Distinct
     * from the violation flag so receipts are auditable: a violation is a
     * real breach; an unevaluable check is an honest "could not evaluate."
     */
    public const FLAG_SPEC_UNEVALUABLE = 'spec_unevaluable';

    /**
     * Evaluate the diff's touched files against the task's spec/constitution.
     *
     * @param  MiniProgrammingSpec  $spec  the persisted spec carrying
     *                                     acceptanceCriteria, nonGoals,
     *                                     forbiddenFiles, allowedFiles,
     *                                     expectedFiles, expectedBehavior.
     * @param  list<string>  $touchedFilePaths  the file paths the diff touched
     *                                          (from the scope receipt's
     *                                          fileDiffs).
     * @return SpecConstitutionVerdict the verdict (no-op / pass / tripped).
     *                                 The executor routes tripped/unevaluable through the advisory
     *                                 or hard channel based on the e6.mode config.
     */
    public function evaluate(MiniProgrammingSpec $spec, array $touchedFilePaths): SpecConstitutionVerdict
    {
        // Check 1: NO-OP — no constitution to validate (VAL-M2-034).
        // A spec with zero acceptance criteria, non-goals, forbidden files,
        // and expected behavior has nothing to validate. E6 surfaces nothing.
        if ($spec->acceptanceCriteria === []
            && $spec->nonGoals === []
            && $spec->forbiddenFiles === []
            && $spec->expectedBehavior === []
        ) {
            return SpecConstitutionVerdict::noOp();
        }

        $flags = [];
        $reasons = [];

        // Check 2: FORBIDDEN FILES (VAL-M2-024).
        // The semantic forbidden-file check: a touched file that matches a
        // spec-declared forbidden file is a constitution breach, even when
        // the file is mechanically within the contract's allowed_files.
        foreach ($touchedFilePaths as $touchedPath) {
            foreach ($spec->forbiddenFiles as $forbidden) {
                if ($this->pathMatches($touchedPath, $forbidden)) {
                    $flags[] = self::FLAG_SPEC_CONSTITUTION_VIOLATION;
                    $reasons[] = "forbidden file touched: {$touchedPath} (declared forbidden: {$forbidden})";
                }
            }
        }

        // Check 3: NON-GOALS (VAL-M2-024).
        // For each non-goal string, extract file-path-like tokens and check
        // if any touched file matches. A diff that implements a non-goal
        // (touches a file referenced in a non-goal) trips the gate. Non-goals
        // without extractable file paths are not matched — the gate catches
        // the file-path-referencing subset deterministically.
        foreach ($spec->nonGoals as $nonGoal) {
            if (! is_string($nonGoal) || $nonGoal === '') {
                continue;
            }
            $nonGoalPaths = $this->extractFilePaths($nonGoal);
            foreach ($nonGoalPaths as $ngPath) {
                foreach ($touchedFilePaths as $touchedPath) {
                    if ($this->pathMatches($touchedPath, $ngPath)) {
                        $flags[] = self::FLAG_SPEC_CONSTITUTION_VIOLATION;
                        $reasons[] = "non-goal implemented: {$nonGoal} (touched: {$touchedPath})";
                    }
                }
            }
        }

        // Check 4: OUT-OF-SPEC SCOPE CREEP (VAL-M2-021).
        // If the spec declares acceptance criteria AND has a non-empty
        // allowed/expected file scope, any touched NON-TEST file that is NOT
        // in the spec's scope trips the gate — the diff introduces changes
        // not justified by any acceptance criterion. Test files are excluded
        // (tests are verification, not behavior). When the scope is empty,
        // the check is skipped (no scope to compare against — never false-fail).
        $specScopedFiles = array_values(array_unique(array_merge(
            $spec->allowedFiles,
            $spec->expectedFiles,
        )));

        if ($spec->acceptanceCriteria !== [] && $specScopedFiles !== []) {
            foreach ($touchedFilePaths as $touchedPath) {
                if ($this->isTestFile($touchedPath)) {
                    continue;
                }
                if (! $this->isInScope($touchedPath, $specScopedFiles)) {
                    $flags[] = self::FLAG_SPEC_CONSTITUTION_VIOLATION;
                    $reasons[] = "out-of-spec file: {$touchedPath} not justified by any acceptance criterion";
                }
            }
        }

        // Deduplicate flags (order-preserving) and deduplicate reasons.
        $flags = array_values(array_unique($flags));
        $reasons = array_values(array_unique($reasons));

        if ($flags !== []) {
            return SpecConstitutionVerdict::tripped($flags, $reasons);
        }

        // All checks passed — the diff honors the spec/constitution.
        return SpecConstitutionVerdict::pass();
    }

    /**
     * Check whether a touched file path matches a declared spec path pattern.
     * Supports exact match, glob (fnmatch, e.g. "vendor/*"), and directory-
     * prefix match (e.g. "config/" matches "config/app.php").
     */
    private function pathMatches(string $touchedPath, string $specPath): bool
    {
        // Exact match.
        if ($touchedPath === $specPath) {
            return true;
        }

        // Directory-prefix match: "config/" matches "config/app.php".
        if (str_ends_with($specPath, '/') && str_starts_with($touchedPath, $specPath)) {
            return true;
        }

        // Glob match: "vendor/*" matches "vendor/foo/bar.php".
        if (fnmatch($specPath, $touchedPath)) {
            return true;
        }

        return false;
    }

    /**
     * Extract file-path-like tokens from a free-text string (e.g. a non-goal
     * description). Matches substrings that look like file paths: contain a
     * slash or a dot-extension pattern. This is a heuristic — it catches the
     * file-path-referencing subset of non-goals deterministically, without
     * attempting semantic analysis of free-text behavioral non-goals.
     *
     * @return list<string>
     */
    private function extractFilePaths(string $text): array
    {
        // Match tokens that look like file paths: word chars, slashes, dots,
        // hyphens, followed by a dot and a 1-10 char extension.
        preg_match_all('/[a-zA-Z0-9_\/.-]+\.[a-zA-Z]{1,10}/', $text, $matches);

        return $matches[0];
    }

    /**
     * Whether a path is a test file (under tests/). Test files are
     * verification, not behavior — they are excluded from the out-of-spec
     * scope-creep check so adding a test does not trip E6.
     */
    private function isTestFile(string $path): bool
    {
        return str_starts_with($path, 'tests/');
    }

    /**
     * Whether a touched file path is within the spec's declared file scope.
     * Uses {@see pathMatches} for glob/prefix support.
     *
     * @param  list<string>  $scopedFiles
     */
    private function isInScope(string $path, array $scopedFiles): bool
    {
        foreach ($scopedFiles as $scoped) {
            if (! is_string($scoped) || $scoped === '') {
                continue;
            }
            if ($this->pathMatches($path, $scoped)) {
                return true;
            }
        }

        return false;
    }
}
