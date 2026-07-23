<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;

/**
 * Provider diff-quality gate section, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. Deterministic,
 * provider-free static analysis of a committed sandbox diff: blocks large
 * un-tested product changes, deletion-heavy diffs, and contract-only runtime
 * edits without a focused runtime test. Reads the worktree diff through the
 * parent's {@see AutonomousEvolutionSessionService::git()} port; diff-quality
 * thresholds are referenced as qualified constants on the parent.
 */
final class ProviderDiffQualitySection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * Provider output that is technically in-scope can still be operationally
     * unsafe: a bounded task should not rewrite or delete a whole service without
     * touching the focused test. This gate runs before committing the sandbox, so
     * rejected provider output cannot become a branch commit or main merge.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    public function providerDiffQualityGate(string $worktree, array $changedFiles, array $allowedFiles, array $finding, string $scopeProfile): array
    {
        $changedFiles = AreaFocusStringListNormalizer::uniqueStringValues(array_filter($changedFiles, 'is_string'));
        if ($changedFiles === []) {
            return [
                'schema_version' => 'atlas.software_company_stewardship.provider_diff_quality_gate.v1',
                'passed' => true,
                'blockers' => [],
                'reason' => 'no_diff_to_score',
            ];
        }

        $numstat = $this->parent->git($worktree, array_merge(['diff', '--numstat', '--'], $changedFiles));
        if (! $numstat['ok']) {
            return [
                'schema_version' => 'atlas.software_company_stewardship.provider_diff_quality_gate.v1',
                'passed' => false,
                'blockers' => [AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER, 'diff_stats_unavailable'],
                'reason' => 'diff_stats_unavailable',
                'git' => $numstat,
            ];
        }

        $stats = $this->withUntrackedChangedFileStats(
            $worktree,
            $changedFiles,
            $this->parseDiffNumstat((string) $numstat['out']),
        );
        $testChanged = false;
        $testChangedFiles = [];
        $productInsertions = 0;
        $productDeletions = 0;
        $productChanged = [];
        $largeDeletedFiles = [];
        foreach ($stats as $row) {
            $file = (string) ($row['file'] ?? '');
            $insertions = (int) ($row['insertions'] ?? 0);
            $deletions = (int) ($row['deletions'] ?? 0);
            if ($this->isTestFile($file)) {
                $testChanged = true;
                $testChangedFiles[] = $file;

                continue;
            }
            if ($this->isDocumentationFile($file)) {
                continue;
            }

            $productChanged[] = $file;
            $productInsertions += $insertions;
            $productDeletions += $deletions;
            if ($deletions >= AutonomousEvolutionSessionService::DIFF_QUALITY_SINGLE_FILE_DELETIONS_WITHOUT_TEST) {
                $largeDeletedFiles[] = ['file' => $file, 'deletions' => $deletions];
            }
        }

        $productLineDelta = $productInsertions + $productDeletions;
        $reasons = [];
        if ($scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX
            && $productChanged !== []
            && $this->contractOnlyProductDiff($worktree, $productChanged)) {
            $reasons[] = 'contract_only_diff_without_runtime_wiring';
        }
        if ($scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX
            && $productChanged !== []
            && $this->contractBackedRuntimeDiffWithoutFocusedRuntimeTest($productChanged, $testChangedFiles)) {
            $reasons[] = 'runtime_wiring_without_focused_runtime_test';
        }
        if ($productChanged !== [] && ! $testChanged) {
            if ($productLineDelta >= AutonomousEvolutionSessionService::DIFF_QUALITY_LARGE_PRODUCT_LINES_WITHOUT_TEST) {
                $reasons[] = 'large_product_diff_without_test_update';
            }
            if ($productDeletions >= AutonomousEvolutionSessionService::DIFF_QUALITY_PRODUCT_DELETIONS_WITHOUT_TEST) {
                $reasons[] = 'large_product_deletion_without_test_update';
            }
            if ($largeDeletedFiles !== []) {
                $reasons[] = 'large_single_file_deletion_without_test_update';
            }
            if ($productDeletions >= 30 && $productInsertions > 0 && ($productDeletions / max(1, $productInsertions)) >= AutonomousEvolutionSessionService::DIFF_QUALITY_DELETION_RATIO_FLOOR) {
                $reasons[] = 'deletion_heavy_product_diff_without_test_update';
            }
        }

        $reasons = AreaFocusStringListNormalizer::uniqueStringValues($reasons);
        $passed = $reasons === [];

        return [
            'schema_version' => 'atlas.software_company_stewardship.provider_diff_quality_gate.v1',
            'passed' => $passed,
            'blockers' => $passed ? [] : AreaFocusStringListNormalizer::uniqueMergedStringValues([AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER], $reasons),
            'reason' => $passed ? 'diff_quality_acceptable' : $reasons[0],
            'scope_profile' => $scopeProfile,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'stats' => $stats,
            'summary' => [
                'product_changed_files' => AreaFocusStringListNormalizer::uniqueStringValues($productChanged),
                'test_changed' => $testChanged,
                'test_changed_files' => AreaFocusStringListNormalizer::uniqueStringValues($testChangedFiles),
                'product_insertions' => $productInsertions,
                'product_deletions' => $productDeletions,
                'product_line_delta' => $productLineDelta,
                'large_deleted_files' => $largeDeletedFiles,
            ],
            'thresholds' => [
                'large_product_lines_without_test' => AutonomousEvolutionSessionService::DIFF_QUALITY_LARGE_PRODUCT_LINES_WITHOUT_TEST,
                'product_deletions_without_test' => AutonomousEvolutionSessionService::DIFF_QUALITY_PRODUCT_DELETIONS_WITHOUT_TEST,
                'single_file_deletions_without_test' => AutonomousEvolutionSessionService::DIFF_QUALITY_SINGLE_FILE_DELETIONS_WITHOUT_TEST,
                'deletion_ratio_floor' => AutonomousEvolutionSessionService::DIFF_QUALITY_DELETION_RATIO_FLOOR,
            ],
        ];
    }

    /**
     * Contract-only diffs are progress theater in factory_max unless the same
     * cycle wires a runtime/service source. A new `*Contract.php` plus reflection
     * tests can be syntactically valid while leaving the factory no more capable.
     *
     * @param  list<string>  $productChanged
     */
    public function contractOnlyProductDiff(string $worktree, array $productChanged): bool
    {
        foreach ($productChanged as $file) {
            if (! str_ends_with(basename($file), 'Contract.php')) {
                return false;
            }
            if (! $this->isExecutableContractClassFile($worktree, $file)) {
                return true;
            }
        }

        return false;
    }

    public function isExecutableContractClassFile(string $worktree, string $file): bool
    {
        $path = rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        if (! preg_match('/\bfinal\s+class\s+\w+Contract\b/', $contents)) {
            return false;
        }
        if (preg_match('/\binterface\s+\w+Contract\b|\babstract\s+class\b/', $contents) === 1) {
            return false;
        }

        $methodSignals = [
            'public function toArray(',
            'public static function fromArray(',
            'public static function defaults(',
            'public function score(',
            'public function validate(',
            'public function classify(',
        ];
        $hasExecutableMethod = false;
        foreach ($methodSignals as $signal) {
            if (str_contains($contents, $signal)) {
                $hasExecutableMethod = true;

                break;
            }
        }
        if (! $hasExecutableMethod) {
            return false;
        }

        foreach (['return [', 'match (', 'if (', 'max(', 'min('] as $computedSignal) {
            if (str_contains($contents, $computedSignal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * AP-806 semantic contract slices are only useful when the provider wires a
     * real runtime entrypoint and updates a focused runtime test in the same
     * bounded diff. A contract test alone can prove shape while the service path
     * remains unverified, which is exactly the low-yield branch pollution the
     * factory_max loop must reject before commit.
     *
     * @param  list<string>  $productChanged
     * @param  list<string>  $testChangedFiles
     */
    public function contractBackedRuntimeDiffWithoutFocusedRuntimeTest(array $productChanged, array $testChangedFiles): bool
    {
        $hasContractProduct = false;
        $hasRuntimeProduct = false;
        foreach ($productChanged as $file) {
            if (str_ends_with(basename($file), 'Contract.php')) {
                $hasContractProduct = true;

                continue;
            }
            $hasRuntimeProduct = true;
        }

        if (! $hasContractProduct || ! $hasRuntimeProduct) {
            return false;
        }

        foreach ($testChangedFiles as $file) {
            if (! str_ends_with(basename($file), 'ContractTest.php')) {
                return false;
            }
        }

        return true;
    }

    /**
     * `git diff --numstat` does not report untracked files. Provider outputs often
     * create new files before AP-786 commits them, so the quality gate must score
     * those files directly or new contract-only scaffolds look like an empty diff.
     *
     * @param  list<string>  $changedFiles
     * @param  list<array{file:string,insertions:int,deletions:int,binary:bool}>  $stats
     * @return list<array{file:string,insertions:int,deletions:int,binary:bool}>
     */
    public function withUntrackedChangedFileStats(string $worktree, array $changedFiles, array $stats): array
    {
        $seen = [];
        foreach ($stats as $row) {
            $seen[(string) ($row['file'] ?? '')] = true;
        }

        foreach ($changedFiles as $file) {
            if (isset($seen[$file])) {
                continue;
            }

            $path = rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            $stats[] = [
                'file' => $file,
                'insertions' => $contents === '' ? 0 : substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1),
                'deletions' => 0,
                'binary' => false,
            ];
        }

        return $stats;
    }

    /**
     * @return list<array{file:string,insertions:int,deletions:int,binary:bool}>
     */
    public function parseDiffNumstat(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t+/', $line);
            if (! is_array($parts) || count($parts) < 3) {
                continue;
            }
            $binary = $parts[0] === '-' || $parts[1] === '-';
            $file = (string) $parts[2];
            if (str_contains($file, ' => ')) {
                $file = (string) preg_replace('/.* => /', '', $file);
                $file = trim($file, '{} ');
            }
            $rows[] = [
                'file' => $file,
                'insertions' => $binary ? 0 : max(0, (int) $parts[0]),
                'deletions' => $binary ? 0 : max(0, (int) $parts[1]),
                'binary' => $binary,
            ];
        }

        return $rows;
    }

    public function isTestFile(string $file): bool
    {
        return str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php');
    }

    public function isDocumentationFile(string $file): bool
    {
        return str_starts_with($file, 'docs/') || preg_match('/\.(md|mdx|rst|txt)\z/i', $file) === 1;
    }
}
