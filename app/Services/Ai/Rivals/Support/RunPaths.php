<?php

namespace App\Services\Ai\Rivals\Support;

use InvalidArgumentException;

/** Fonte única do layout de disco do Rivals 2.0 (storage/atlas/rivals). */
class RunPaths
{
    public static function root(): string
    {
        return rtrim(config('atlas_rivals.storage_root'), '/');
    }

    public static function ledgerPath(): string
    {
        return self::root().'/ledger.jsonl';
    }

    public static function runsDir(): string
    {
        return self::root().'/runs';
    }

    public static function runDir(string $runId): string
    {
        self::assertIdentifier($runId, 'run_id');

        return self::runsDir().'/'.$runId;
    }

    public static function planPath(string $runId): string
    {
        return self::runDir($runId).'/plan.json';
    }

    public static function receiptsPath(string $runId): string
    {
        return self::runDir($runId).'/receipts.jsonl';
    }

    public static function nativeManifestPath(string $runId): string
    {
        return self::runDir($runId).'/native_execution_manifest.json';
    }

    public static function preregistrationPath(string $runId): string
    {
        return self::runDir($runId).'/preregistration.json';
    }

    public static function nativeResultsDir(string $runId): string
    {
        return self::runDir($runId).'/external_results/units';
    }

    public static function nativeReceiptsDir(string $runId): string
    {
        return self::runDir($runId).'/native_execution_receipts';
    }

    public static function artifactsDir(string $runId): string
    {
        return self::runDir($runId).'/artifacts';
    }

    public static function evidencePath(string $runId): string
    {
        return self::runDir($runId).'/evidence_pack.json';
    }

    public static function evidenceStateSnapshotPath(string $runId): string
    {
        return self::runDir($runId).'/evidence_state_snapshot.json';
    }

    public static function evidenceEventsSnapshotPath(string $runId): string
    {
        return self::runDir($runId).'/evidence_events_snapshot.jsonl';
    }

    public static function smokeSnapshotPath(string $runId): string
    {
        return self::runDir($runId).'/smoke_receipt.json';
    }

    public static function adjudicationPath(string $runId): string
    {
        return self::runDir($runId).'/adjudication.json';
    }

    public static function reportPath(string $runId): string
    {
        return self::runDir($runId).'/report.json';
    }

    public static function reportMarkdownPath(string $runId): string
    {
        return self::runDir($runId).'/report.md';
    }

    public static function reportCsvPath(string $runId): string
    {
        return self::runDir($runId).'/report.csv';
    }

    public static function bundleManifestPath(string $runId): string
    {
        return self::runDir($runId).'/bundle_manifest.json';
    }

    public static function closureReceiptPath(): string
    {
        return self::root().'/fase_a_closure_receipt.json';
    }

    public static function eventsPath(string $runId): string
    {
        return self::runDir($runId).'/events.jsonl';
    }

    public static function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public static function latestRunId(): ?string
    {
        if (! is_dir(self::runsDir())) {
            return null;
        }
        $runs = array_values(array_filter(
            array_diff(scandir(self::runsDir()), ['.', '..']),
            fn (string $run): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $run) === 1
                && is_dir(self::runsDir().'/'.$run),
        ));
        if ($runs === []) {
            return null;
        }
        // run_id embute timestamp sortável (ver RunPlan::newRunId)
        sort($runs);

        return end($runs);
    }

    public static function assertCaseId(string $caseId): void
    {
        self::assertIdentifier($caseId, 'case_id');
    }

    public static function assertRelativePath(string $path): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new InvalidArgumentException('rivals_path_not_relative_or_safe:'.$path);
        }
    }

    public static function resolveContained(
        string $root,
        string $relative,
        bool $mustExist = true,
    ): string {
        self::assertRelativePath($relative);
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new InvalidArgumentException('rivals_containment_root_missing:'.$root);
        }
        $candidate = $rootReal.'/'.$relative;
        $resolved = realpath($candidate);
        if ($resolved === false && ! $mustExist) {
            $parent = realpath(dirname($candidate));
            if ($parent !== false) {
                $resolved = $parent.'/'.basename($candidate);
            }
        }
        if ($resolved === false) {
            throw new InvalidArgumentException('rivals_contained_path_missing:'.$relative);
        }
        if ($resolved !== $rootReal && ! str_starts_with($resolved, $rootReal.'/')) {
            throw new InvalidArgumentException('rivals_path_escape:'.$relative);
        }
        if (is_link($candidate)) {
            throw new InvalidArgumentException('rivals_symlink_forbidden:'.$relative);
        }

        return $resolved;
    }

    public static function assertImportSource(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path)) {
            throw new InvalidArgumentException('rivals_import_source_invalid:'.$path);
        }
        $roots = array_values(array_unique(array_merge(
            (array) config('atlas_rivals.import_roots', []),
            [
                base_path(),
                self::root(),
                (string) config('atlas_rivals.benchmarks.root'),
            ],
        )));
        foreach ($roots as $root) {
            $rootReal = realpath((string) $root);
            if ($rootReal !== false
                && ($real === $rootReal || str_starts_with($real, $rootReal.'/'))) {
                return $real;
            }
        }

        throw new InvalidArgumentException('rivals_import_source_outside_allowed_roots:'.$path);
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) !== 1) {
            throw new InvalidArgumentException("rivals_invalid_{$field}:{$value}");
        }
    }
}
