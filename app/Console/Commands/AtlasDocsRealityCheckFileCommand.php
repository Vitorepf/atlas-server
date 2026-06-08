<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocClaimAnalyzer;
use Illuminate\Console\Command;

/**
 * FROZEN fake-implemented verifier — the acceptance for the P3 "code-vs-docs" mode,
 * and the discovery oracle that finds the drift.
 *
 *   --path=<doc>  : per-file FROZEN acceptance. Prints `ATLAS_DOC_PHANTOM=<n>` and
 *                   exits 0 iff every App\… class and `php artisan` command the doc
 *                   claims actually exists. The loop's task is to reconcile the doc
 *                   with reality (correct the claim) — or, when the symbol SHOULD
 *                   exist, the finding is FLAGGED for implementation (P4), not faked.
 *   --scan=<dir>  : walk a docs tree and list every doc making phantom claims.
 *
 * Resolves all claims against the WORKSPACE (CWD), so it is correct inside a scenario
 * copy where the doc is edited and the code is frozen. Reverting the doc edit brings
 * the phantom back — the frozen judge's revert-recheck proves the fix was earned.
 */
final class AtlasDocsRealityCheckFileCommand extends Command
{
    protected $signature = 'atlas:docs:reality-check-file
        {--path= : One markdown doc to verify (frozen per-file acceptance; prints ATLAS_DOC_PHANTOM=<n>)}
        {--scan= : A docs directory to scan for phantom claims (discovery mode)}
        {--workspace= : Root to resolve claims against (default: CWD)}
        {--json : Emit a JSON report too}';

    protected $description = 'Detect fake-implemented docs: App\\ classes / artisan commands a doc claims but the code lacks. Per-file frozen acceptance or docs-tree discovery. Exit 0 iff clean.';

    public function handle(AtlasDocClaimAnalyzer $analyzer): int
    {
        $root = trim((string) $this->option('workspace')) ?: (string) getcwd();

        // Ground-truth command existence from the booted console registry (not a grep).
        $app = $this->getApplication();
        if ($app !== null) {
            $analyzer->useRegisteredCommands(array_keys($app->all()));
        }

        $scan = $this->resolvePath((string) $this->option('scan'));
        if ($scan !== null) {
            return $this->runScan($analyzer, $scan, $root);
        }

        $path = $this->resolvePath((string) $this->option('path'));
        if ($path === null || ! is_file($path)) {
            $this->line('ATLAS_DOC_PHANTOM=999');
            $this->error('doc not found: '.(string) $this->option('path'));

            return self::FAILURE;
        }

        $report = $analyzer->analyzeFile($path, $root);
        if (! $report['readable']) {
            $this->line('ATLAS_DOC_PHANTOM=999');
            $this->error('unreadable doc — failing closed');

            return self::FAILURE;
        }

        $n = count($report['phantoms']);
        $this->line('ATLAS_DOC_PHANTOM='.$n);
        if ((bool) $this->option('json')) {
            $this->line('ATLAS_DOC_PHANTOM_REPORT='.$this->json($report));
        } elseif ($n > 0) {
            foreach ($report['phantoms'] as $p) {
                $this->line(sprintf('  phantom %s: %s (%s)', $p['kind'], $p['symbol'], $p['reason']));
            }
        }

        return $n === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runScan(AtlasDocClaimAnalyzer $analyzer, string $dir, string $root): int
    {
        if (! is_dir($dir)) {
            $this->error('not a directory: '.$dir);

            return self::FAILURE;
        }

        $docs = 0;
        $withPhantoms = 0;
        $total = 0;
        $rows = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! ($file instanceof \SplFileInfo) || ! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $docs++;
            $report = $analyzer->analyzeFile($file->getPathname(), $root);
            if (! $report['readable'] || $report['phantoms'] === []) {
                continue;
            }
            $withPhantoms++;
            $total += count($report['phantoms']);
            $rows[] = ['path' => $file->getPathname(), 'count' => count($report['phantoms']), 'phantoms' => $report['phantoms']];
        }
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'schema_version' => AtlasDocClaimAnalyzer::SCHEMA.'.scan',
                'root' => $dir,
                'docs_scanned' => $docs,
                'docs_with_phantoms' => $withPhantoms,
                'total_phantoms' => $total,
                'findings' => $rows,
            ]));
        } else {
            $this->info(sprintf('Scanned %d docs · %d with phantom claims · %d phantoms', $docs, $withPhantoms, $total));
            foreach (array_slice($rows, 0, 40) as $r) {
                $this->line(sprintf('  %3d  %s', $r['count'], $r['path']));
            }
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (! str_starts_with($raw, '/')) {
            $raw = rtrim((string) getcwd(), '/').'/'.$raw;
        }

        return $raw;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
