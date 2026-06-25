<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiBreakingChangeReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceDiffer;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceExtractor;
use Illuminate\Console\Command;

/**
 * Operator surface for the Cortex API-diff sub-system.
 *   atlas:loop:cortex:api extract  --class=FQCN --out=<path.json>
 *   atlas:loop:cortex:api diff     --class=FQCN --a=A.json --b=B.json [--format=json]
 *   atlas:loop:cortex:api breaking --class=FQCN --diff=D.json
 *   atlas:loop:cortex:api history  --class=FQCN
 *
 * FACTS-only: never emits scoring, recommendation prose, severity/risk fields.
 */
final class AtlasLoopCortexApiCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const SNAPSHOT_ROOT_REL = 'atlas/cortex/api-snapshots';

    protected $signature = 'atlas:loop:cortex:api {action : extract|diff|breaking|history}
        {--class= : target FQCN}
        {--a= : snapshot A path (diff)}
        {--b= : snapshot B path (diff)}
        {--diff= : diff JSON path (breaking)}
        {--out= : output JSON path (extract)}
        {--format=json : json|table}';

    protected $description = 'Cortex API-surface CLI: extract | diff | breaking | history (FACTS-only).';

    public function handle(
        AtlasCortexApiSurfaceExtractor $extractor,
        AtlasCortexApiSurfaceDiffer $differ,
        AtlasCortexApiBreakingChangeReporter $reporter,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'extract' => $this->extract($extractor),
            'diff' => $this->diff($differ),
            'breaking' => $this->breaking($reporter),
            'history' => $this->history(),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function extract(AtlasCortexApiSurfaceExtractor $extractor): int
    {
        $class = (string) ($this->option('class') ?? '');
        $out = (string) ($this->option('out') ?? '');
        if ($class === '' || $out === '') {
            return $this->refuse('extract_requires_class_and_out');
        }
        if (! class_exists($class)) {
            return $this->refuse('class_not_found:'.$class);
        }

        $snapshot = $extractor->extractForClass($class);
        $payload = $this->sortRecursive($snapshot);
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $dir = \dirname($out);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($out, $json);

        // Mirror to snapshots history root for the class.
        $this->persistHistory($class, $json);

        $this->emit(['extracted_for' => $class, 'out' => $out, 'method_count' => count($snapshot)]);

        return self::EXIT_OK;
    }

    private function diff(AtlasCortexApiSurfaceDiffer $differ): int
    {
        $class = (string) ($this->option('class') ?? '');
        $a = (string) ($this->option('a') ?? '');
        $b = (string) ($this->option('b') ?? '');
        if ($class === '' || $a === '' || $b === '') {
            return $this->refuse('diff_requires_class_a_b');
        }
        if (! is_file($a) || ! is_file($b)) {
            return $this->refuse('snapshot_file_not_found');
        }
        $snapA = (array) json_decode((string) file_get_contents($a), true);
        $snapB = (array) json_decode((string) file_get_contents($b), true);
        $rows = $differ->diff($snapA, $snapB, $class);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function breaking(AtlasCortexApiBreakingChangeReporter $reporter): int
    {
        $class = (string) ($this->option('class') ?? '');
        $diffPath = (string) ($this->option('diff') ?? '');
        if ($class === '' || $diffPath === '') {
            return $this->refuse('breaking_requires_class_and_diff');
        }
        if (! is_file($diffPath)) {
            return $this->refuse('diff_file_not_found');
        }
        $diff = (array) json_decode((string) file_get_contents($diffPath), true);
        $candidates = $this->scanConsumerCandidates($class);
        $rows = $reporter->report($diff, $class, $candidates);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $class = (string) ($this->option('class') ?? '');
        if ($class === '') {
            return $this->refuse('history_requires_class');
        }
        $root = $this->snapshotRootForClass($class);
        if (! is_dir($root)) {
            $this->emit([]);

            return self::EXIT_OK;
        }
        $files = glob($root.'/*.json') ?: [];
        sort($files, SORT_STRING);
        $rows = [];
        foreach ($files as $f) {
            $rows[] = ['path' => $f, 'mtime' => @filemtime($f) ?: 0];
        }
        $this->emit($rows);

        return self::EXIT_OK;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    private function scanConsumerCandidates(string $fqcn): iterable
    {
        // FACT-only: scan app/ for files mentioning the FQCN (short name as fallback).
        $short = (str_contains($fqcn, '\\')) ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
        $needle = $short;
        $root = function_exists('app_path') ? app_path() : 'app';
        $candidates = [];
        if (! is_dir($root)) {
            return $candidates;
        }
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile() || ! str_ends_with($info->getFilename(), '.php')) {
                continue;
            }
            $contents = (string) file_get_contents($info->getPathname());
            if ($contents === '' || ! str_contains($contents, $needle)) {
                continue;
            }
            $candidates[] = [
                'consumer_fqcn' => $this->guessFqcn($contents, $info->getPathname()),
                'consumer_file' => $info->getPathname(),
            ];
        }

        return $candidates;
    }

    private function guessFqcn(string $contents, string $path): string
    {
        if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $contents, $m)) {
            return $m[1].'\\'.pathinfo($path, PATHINFO_FILENAME);
        }

        return pathinfo($path, PATHINFO_FILENAME);
    }

    private function snapshotRootForClass(string $fqcn): string
    {
        $slug = strtolower(str_replace('\\', '-', trim($fqcn, '\\')));
        $base = function_exists('storage_path')
            ? storage_path(self::SNAPSHOT_ROOT_REL)
            : sys_get_temp_dir().'/'.self::SNAPSHOT_ROOT_REL;

        return $base.'/'.$slug;
    }

    private function persistHistory(string $class, string $json): void
    {
        $root = $this->snapshotRootForClass($class);
        if (! is_dir($root)) {
            @mkdir($root, 0o755, true);
        }
        $path = $root.'/'.gmdate('Ymd-His').'-'.substr(hash('sha256', $json), 0, 8).'.json';
        @file_put_contents($path, $json);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $format = (string) ($this->option('format') ?? 'json');
        if ($format === 'json' || $format === '') {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        // table: emit each row as "k=v k=v ..."
        if ($payload === []) {
            return;
        }
        if (array_is_list($payload)) {
            foreach ($payload as $row) {
                if (is_array($row)) {
                    $cells = [];
                    foreach ($row as $k => $v) {
                        $cells[] = $k.'='.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES));
                    }
                    $this->getOutput()->writeln(implode(' ', $cells));
                }
            }

            return;
        }
        foreach ($payload as $k => $v) {
            $this->getOutput()->writeln($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
