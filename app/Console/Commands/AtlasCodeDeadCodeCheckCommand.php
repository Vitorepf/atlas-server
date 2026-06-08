<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use Illuminate\Console\Command;

/**
 * FROZEN dead-code verifier — the acceptance for the P3 "dead code" evolution mode,
 * and the discovery oracle that surfaces the work in the first place.
 *
 *   --path=<file>  : per-file FROZEN acceptance. Prints `ATLAS_DEADCODE=<n>` and exits
 *                    0 iff the file has zero dead private members. A removal candidate
 *                    drives it N→0; reverting the removal brings the count back (the
 *                    frozen judge's revert-recheck then proves the diff was earned).
 *   --scan=<root>  : repo-wide DISCOVERY. Walks the root, lists every file with dead
 *                    private members so the dispatcher can mint loop tasks from them.
 *
 * Only `private` members are ever reported — that is the subset where a single-file
 * AST scan is provably complete (see {@see AtlasDeadCodeAnalyzer}). Removing flagged
 * dead code cannot change behavior, so a full-suite holdout in the honesty gate is a
 * cheap, decisive backstop.
 */
final class AtlasCodeDeadCodeCheckCommand extends Command
{
    protected $signature = 'atlas:code:deadcode-check
        {--path= : One PHP file to verify (frozen per-file acceptance; prints ATLAS_DEADCODE=<n>)}
        {--scan= : A directory to scan for dead code (discovery mode; relative to CWD unless absolute)}
        {--max-files=4000 : Discovery: cap files walked}
        {--json : Emit a JSON report too}';

    protected $description = 'Detect dead PRIVATE members (zero in-class references) via AST. Per-file frozen acceptance or repo-wide discovery. Exit 0 iff clean.';

    public function handle(AtlasDeadCodeAnalyzer $analyzer): int
    {
        $scan = $this->resolvePath((string) $this->option('scan'));
        if ($scan !== null) {
            return $this->runScan($analyzer, $scan);
        }

        $path = $this->resolvePath((string) $this->option('path'));
        if ($path === null || ! is_file($path)) {
            $this->line('ATLAS_DEADCODE=999');
            $this->error('file not found: '.(string) $this->option('path'));

            return self::FAILURE;
        }

        $report = $analyzer->analyzeFile($path);
        $n = count($report['dead']);

        // A file we could not parse is INCONCLUSIVE, not clean — fail closed so the loop
        // never "fixes" dead code in a file the oracle cannot actually read.
        if (! $report['parseable']) {
            $this->line('ATLAS_DEADCODE=999');
            if ((bool) $this->option('json')) {
                $this->line('ATLAS_DEADCODE_REPORT='.$this->json($report));
            } else {
                $this->error('unparseable ('.(string) ($report['note'] ?? 'unknown').') — inconclusive, failing closed');
            }

            return self::FAILURE;
        }

        $this->line('ATLAS_DEADCODE='.$n);
        if ((bool) $this->option('json')) {
            $this->line('ATLAS_DEADCODE_REPORT='.$this->json($report));
        } elseif ($n > 0) {
            foreach ($report['dead'] as $d) {
                $this->line(sprintf('  dead %s %s::%s (line %d)', $d['kind'], $d['class'], $d['name'], $d['line']));
            }
        }

        return $n === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runScan(AtlasDeadCodeAnalyzer $analyzer, string $root): int
    {
        if (! is_dir($root)) {
            $this->error('not a directory: '.$root);

            return self::FAILURE;
        }

        $maxFiles = max(1, (int) $this->option('max-files'));
        $files = 0;
        $withDead = 0;
        $totalDead = 0;
        $rows = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($files >= $maxFiles) {
                break;
            }
            if (! ($file instanceof \SplFileInfo) || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $report = $analyzer->analyzeFile($file->getPathname());
            if (! $report['parseable'] || $report['dead'] === []) {
                continue;
            }
            $withDead++;
            $totalDead += count($report['dead']);
            $rows[] = ['path' => $file->getPathname(), 'count' => count($report['dead']), 'dead' => $report['dead']];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        if ((bool) $this->option('json')) {
            $this->line($this->json([
                'schema_version' => AtlasDeadCodeAnalyzer::SCHEMA.'.scan',
                'root' => $root,
                'files_scanned' => $files,
                'files_with_dead' => $withDead,
                'total_dead_members' => $totalDead,
                'findings' => $rows,
            ]));
        } else {
            $this->info(sprintf('Scanned %d files · %d with dead code · %d dead members', $files, $withDead, $totalDead));
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
