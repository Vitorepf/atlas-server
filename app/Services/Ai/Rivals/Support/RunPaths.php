<?php

namespace App\Services\Ai\Rivals\Support;

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

    public static function artifactsDir(string $runId): string
    {
        return self::runDir($runId).'/artifacts';
    }

    public static function evidencePath(string $runId): string
    {
        return self::runDir($runId).'/evidence_pack.json';
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
        $runs = array_values(array_diff(scandir(self::runsDir()), ['.', '..']));
        if ($runs === []) {
            return null;
        }
        // run_id embute timestamp sortável (ver RunPlan::newRunId)
        sort($runs);

        return end($runs);
    }
}
