<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Classifies whether a scope contract can be satisfied at all, by checking the
 * declared allowed files against the forbidden patterns using the SAME matching
 * semantics as ScopeGuard (mirrored inline, never imported), so a glob/prefix
 * forbidden rule that swallows every allowed target is caught before execution
 * instead of producing a silent false-negative (allow-list looks fine, but every
 * write is actually blocked).
 *
 * Pure: every returned field is computed from the method inputs.
 */
final class ScopeContractFeasibilityClassifier
{
    private const SCHEMA_VERSION = 'atlas.programming.scope_contract_feasibility.v1';

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return array{schema_version: string, verdict: string, dead_allowed_files: list<string>, reason: string, satisfiable_allowed_count: int}
     */
    public function classify(array $allowedFiles, array $forbiddenFiles, int $maxFilesChanged): array
    {
        $liveAllowed = [];
        foreach ($allowedFiles as $file) {
            if ($file !== '') {
                $liveAllowed[] = $file;
            }
        }

        $dead = [];
        foreach ($liveAllowed as $file) {
            if ($this->matchesAny($file, $forbiddenFiles)) {
                $dead[] = $file;
            }
        }

        sort($dead, SORT_STRING);
        $dead = array_values($dead);

        $liveCount = count($liveAllowed);
        $deadCount = count($dead);
        $satisfiableAllowedCount = $liveCount - $deadCount;

        if ($liveCount > 0 && $deadCount === $liveCount) {
            return $this->result(
                'unsatisfiable',
                $dead,
                'R1: every non-empty allowed file is caught by a forbidden pattern.',
                $satisfiableAllowedCount,
            );
        }

        if ($deadCount > 0) {
            return $this->result(
                'degraded',
                $dead,
                'R2: some-but-not-all allowed files are caught by a forbidden pattern.',
                $satisfiableAllowedCount,
            );
        }

        if ($maxFilesChanged > 0 && $satisfiableAllowedCount > $maxFilesChanged) {
            return $this->result(
                'degraded',
                $dead,
                'R3: satisfiable allowed file count exceeds maxFilesChanged.',
                $satisfiableAllowedCount,
            );
        }

        if ($liveCount === 0) {
            return $this->result(
                'unsatisfiable',
                $dead,
                'R4: scope contract declares no non-empty allowed target.',
                $satisfiableAllowedCount,
            );
        }

        return $this->result(
            'feasible',
            [],
            'R5: scope contract is satisfiable within the declared bounds.',
            $satisfiableAllowedCount,
        );
    }

    /**
     * @param  list<string>  $deadAllowedFiles
     * @return array{schema_version: string, verdict: string, dead_allowed_files: list<string>, reason: string, satisfiable_allowed_count: int}
     */
    private function result(string $verdict, array $deadAllowedFiles, string $reason, int $satisfiableAllowedCount): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'dead_allowed_files' => $deadAllowedFiles,
            'reason' => $reason,
            'satisfiable_allowed_count' => $satisfiableAllowedCount,
        ];
    }

    /**
     * Mirrors ScopeGuard::matchesAny exactly. Do not import or call ScopeGuard.
     *
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $file) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $file, FNM_NOESCAPE)) {
                return true;
            }
            if (str_ends_with($pattern, '/') && str_starts_with($file, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
