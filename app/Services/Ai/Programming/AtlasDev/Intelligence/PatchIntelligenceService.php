<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\BlastRadius;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;

/**
 * Produces a PatchIntelligenceReceipt from a PatchIntelligenceInput.
 *
 * Deterministic, side-effect free: same input -> same output (and same
 * receipt_hash). No filesystem touches, no network, no time. Heuristics are
 * intentionally simple — the value is in surfacing risk/blast/rollback
 * structure that downstream auditors and the operator can act on, not in
 * fancy ML.
 *
 * Risk scoring rubric (highest match wins):
 *   - critical: secret/env file touched, OR a tracked user pre-existing
 *     change was overwritten, OR >12 files changed.
 *   - high:    >6 files, OR migration/schema/config dir touched, OR
 *     lines_added+removed >= 200, OR there are unexpected files when
 *     expected_files was non-empty.
 *   - medium:  >2 files, OR lines_added+removed >= 60.
 *   - low:     anything else (incl. empty diff).
 */
final class PatchIntelligenceService
{
    private const SECRET_PATTERNS = [
        '.env',
        'secrets',
        'credentials',
        'auth.json',
    ];

    private const HIGH_RISK_DIR_PATTERNS = [
        'database/migrations/',
        'config/',
        'app/Providers/',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
    ];

    public function analyze(PatchIntelligenceInput $input): PatchIntelligenceReceipt
    {
        $expectedFiles = $this->normalizeExpected($input->expectedFiles);
        $changedFiles = $input->changedFiles;
        $changedPaths = $this->changedPaths($changedFiles);

        $unexpected = $expectedFiles === []
            ? []
            : array_values(array_diff($changedPaths, $expectedFiles));
        $missing = $expectedFiles === []
            ? []
            : array_values(array_diff($expectedFiles, $changedPaths));

        $blast = $this->computeBlastRadius($changedFiles);
        $riskLevel = $this->scoreRisk(
            changedFiles: $changedFiles,
            changedPaths: $changedPaths,
            unexpected: $unexpected,
            expectedFilesProvided: $expectedFiles !== [],
            blast: $blast,
            userPreExistingChanges: $input->userPreExistingChanges,
        );

        $patternNotes = $this->collectPatternNotes($changedPaths, $blast);
        $preservationNotes = $this->preservationNotes(
            $input->userPreExistingChanges,
            $changedPaths,
        );
        $rollbackHint = $this->rollbackHint($changedPaths, $riskLevel);

        return PatchIntelligenceReceipt::issue(
            runId: $input->runId,
            taskContractHash: $input->taskContractHash,
            expectedFiles: $expectedFiles,
            changedFiles: array_values($changedFiles),
            unexpectedFiles: $unexpected,
            missingExpectedFiles: $missing,
            riskLevel: $riskLevel,
            blastRadius: $blast,
            localPatternNotes: $patternNotes,
            userChangePreservationNotes: $preservationNotes,
            userPreExistingChanges: array_values($input->userPreExistingChanges),
            rollbackHint: $rollbackHint,
            evidenceRefs: array_values($input->evidenceRefs),
        );
    }

    /**
     * @param  list<string>  $expectedFiles
     * @return list<string>
     */
    private function normalizeExpected(array $expectedFiles): array
    {
        return array_values(array_unique(array_filter(
            $expectedFiles,
            static fn (string $f): bool => $f !== '',
        )));
    }

    /**
     * @param  list<ScopeFileDiff>  $diffs
     * @return list<string>
     */
    private function changedPaths(array $diffs): array
    {
        return array_values(array_map(
            static fn (ScopeFileDiff $d): string => $d->path,
            $diffs,
        ));
    }

    /**
     * @param  list<ScopeFileDiff>  $diffs
     */
    private function computeBlastRadius(array $diffs): BlastRadius
    {
        $added = 0;
        $removed = 0;
        $dirs = [];
        foreach ($diffs as $diff) {
            $added += $diff->added;
            $removed += $diff->removed;
            $dirs[] = $this->topLevelDir($diff->path);
        }
        sort($dirs);
        $dirs = array_values(array_unique($dirs));

        return new BlastRadius(
            fileCount: count($diffs),
            linesAddedTotal: $added,
            linesRemovedTotal: $removed,
            touchedDirs: $dirs,
        );
    }

    private function topLevelDir(string $path): string
    {
        $clean = ltrim($path, './');
        $segments = explode('/', $clean);
        if (count($segments) <= 1) {
            return $clean === '' ? '.' : $clean;
        }
        if (count($segments) === 2) {
            return $segments[0];
        }

        return $segments[0].'/'.$segments[1];
    }

    /**
     * @param  list<ScopeFileDiff>  $changedFiles
     * @param  list<string>  $changedPaths
     * @param  list<string>  $unexpected
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     */
    private function scoreRisk(
        array $changedFiles,
        array $changedPaths,
        array $unexpected,
        bool $expectedFilesProvided,
        BlastRadius $blast,
        array $userPreExistingChanges,
    ): string {
        if ($this->touchesSecretPath($changedPaths)) {
            return PatchIntelligenceReceipt::RISK_CRITICAL;
        }
        if ($this->overwritesUserChanges($userPreExistingChanges, $changedPaths)) {
            return PatchIntelligenceReceipt::RISK_CRITICAL;
        }
        if ($blast->fileCount > 12) {
            return PatchIntelligenceReceipt::RISK_CRITICAL;
        }
        if ($blast->fileCount > 6) {
            return PatchIntelligenceReceipt::RISK_HIGH;
        }
        if ($this->touchesHighRiskDir($changedPaths)) {
            return PatchIntelligenceReceipt::RISK_HIGH;
        }
        if (($blast->linesAddedTotal + $blast->linesRemovedTotal) >= 200) {
            return PatchIntelligenceReceipt::RISK_HIGH;
        }
        if ($expectedFilesProvided && $unexpected !== []) {
            return PatchIntelligenceReceipt::RISK_HIGH;
        }
        if ($blast->fileCount > 2) {
            return PatchIntelligenceReceipt::RISK_MEDIUM;
        }
        if (($blast->linesAddedTotal + $blast->linesRemovedTotal) >= 60) {
            return PatchIntelligenceReceipt::RISK_MEDIUM;
        }

        return PatchIntelligenceReceipt::RISK_LOW;
    }

    /**
     * @param  list<string>  $paths
     */
    private function touchesSecretPath(array $paths): bool
    {
        foreach ($paths as $path) {
            foreach (self::SECRET_PATTERNS as $pattern) {
                if (str_contains($path, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     */
    private function touchesHighRiskDir(array $paths): bool
    {
        foreach ($paths as $path) {
            foreach (self::HIGH_RISK_DIR_PATTERNS as $pattern) {
                if (str_starts_with($path, $pattern) || $path === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     * @param  list<string>  $changedPaths
     */
    private function overwritesUserChanges(array $userPreExistingChanges, array $changedPaths): bool
    {
        $preserved = array_flip($changedPaths);
        foreach ($userPreExistingChanges as $change) {
            if (array_key_exists($change->path, $preserved)) {
                // The patch touched a file the user already had local edits in.
                // That's the classic blind-edit failure; flag as critical so the
                // operator must explicitly confirm the merge was intentional.
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $changedPaths
     */
    private function collectPatternNotes(array $changedPaths, BlastRadius $blast): array
    {
        $notes = [];
        $dirs = $blast->touchedDirs;
        if (in_array('database/migrations', $dirs, true) || in_array('database', $dirs, true)) {
            $notes[] = 'patch touches database/migrations — schema invariants live in the DB; do not edit migrations after they have been applied in production.';
        }
        if (in_array('config', $dirs, true) || $this->anyStartsWith($changedPaths, 'config/')) {
            $notes[] = 'patch touches config/ — prefer changing config + adding env-fallback over hardcoding values.';
        }
        if ($this->anyStartsWith($changedPaths, 'app/Providers/')) {
            $notes[] = 'patch touches app/Providers/ — service bindings impact the whole container; rerun integration tests.';
        }
        if ($this->anyStartsWith($changedPaths, 'tests/Feature/')) {
            $notes[] = 'patch adds/edits tests/Feature/* — use Creates*Tables traits and avoid RefreshDatabase per local convention.';
        }
        if ($this->anyStartsWith($changedPaths, 'docs/engineering-knowledge-base/')) {
            $notes[] = 'patch touches engineering-knowledge-base — run `php artisan atlas:engineering:knowledge docs-health --json` before merge.';
        }

        return $notes;
    }

    /**
     * @param  list<string>  $paths
     */
    private function anyStartsWith(array $paths, string $prefix): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     * @param  list<string>  $changedPaths
     * @return list<string>
     */
    private function preservationNotes(array $userPreExistingChanges, array $changedPaths): array
    {
        if ($userPreExistingChanges === []) {
            return [];
        }
        $changed = array_flip($changedPaths);
        $notes = [];
        foreach ($userPreExistingChanges as $change) {
            if (array_key_exists($change->path, $changed)) {
                $notes[] = "user pre-existing change at [{$change->path}] was overwritten by this patch — confirm the merge was intentional before completing.";
            } else {
                $notes[] = "user pre-existing change at [{$change->path}] left untouched by this patch.";
            }
        }

        return $notes;
    }

    /**
     * @param  list<string>  $changedPaths
     */
    private function rollbackHint(array $changedPaths, string $riskLevel): string
    {
        if ($changedPaths === []) {
            return 'no_op: this patch changed no files; nothing to roll back.';
        }
        if ($riskLevel === PatchIntelligenceReceipt::RISK_CRITICAL) {
            return 'CRITICAL: run `git restore --source=HEAD --staged --worktree -- '
                .implode(' ', $changedPaths)
                .'` then audit the workspace before retrying. Do NOT auto-revert if secrets or user pre-existing changes were touched.';
        }
        if (count($changedPaths) <= 4) {
            return 'run `git restore --source=HEAD --staged --worktree -- '
                .implode(' ', $changedPaths)
                .'` to revert to HEAD; inspect the diff first.';
        }

        return 'run `git restore --source=HEAD --staged --worktree -- '
            .implode(' ', array_slice($changedPaths, 0, 4))
            .' ...` (4 of '.count($changedPaths).' shown). Prefer a fresh branch from HEAD and cherry-pick only intended changes.';
    }
}
