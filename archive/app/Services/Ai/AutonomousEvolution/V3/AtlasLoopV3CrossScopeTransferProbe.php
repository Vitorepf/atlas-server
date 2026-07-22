<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AtlasLoopV3CrossScopeTransferProbe
{
    public const SCHEMA = 'atlas.loop.v3.cross_scope_transfer_probe.v1';

    /**
     * @param  array<string,mixed>  $fingerprint
     * @return array{verdict:string,transferable:bool,candidates:list<string>,already_wired:list<string>,scanned:int,note:?string,schema:string}
     */
    public function probe(array $fingerprint, string $targetRepoRoot, int $maxScan = 200): array
    {
        $fqcn = trim((string) ($fingerprint['fqcn'] ?? ''));
        $patterns = $fingerprint['path_patterns'] ?? null;
        if ($fqcn === '' || ! is_array($patterns) || $this->normalizedPatterns($patterns) === []) {
            throw new InvalidArgumentException('invalid_fingerprint');
        }

        if (! is_dir($targetRepoRoot)) {
            throw new InvalidArgumentException('unknown_target_root:'.$targetRepoRoot);
        }

        $root = rtrim($targetRepoRoot, DIRECTORY_SEPARATOR);
        $matches = [];
        foreach ($this->normalizedPatterns($patterns) as $pattern) {
            foreach ($this->matchingFiles($root, $pattern) as $file) {
                $matches[$file] = $file;
            }
        }

        $matches = array_values($matches);
        sort($matches, SORT_STRING);

        $limit = max(0, $maxScan);
        $scanCapped = count($matches) > $limit;
        $scannedFiles = array_slice($matches, 0, $limit);
        $shortName = $this->shortName($fqcn);

        $candidates = [];
        $alreadyWired = [];
        foreach ($scannedFiles as $file) {
            $relative = $this->relativePath($root, $file);
            $contents = (string) @file_get_contents($file);

            if ($this->containsToken($contents, $shortName)) {
                $alreadyWired[] = $relative;
            } else {
                $candidates[] = $relative;
            }
        }

        sort($candidates, SORT_STRING);
        sort($alreadyWired, SORT_STRING);

        if ($scannedFiles === []) {
            return $this->result('no_material', false, [], [], 0, $scanCapped ? 'scan_capped' : null);
        }

        if ($candidates === []) {
            return $this->result('saturated', false, [], $alreadyWired, count($scannedFiles), $scanCapped ? 'scan_capped' : null);
        }

        return $this->result('transferable', true, $candidates, $alreadyWired, count($scannedFiles), $scanCapped ? 'scan_capped' : null);
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $alreadyWired
     * @return array{verdict:string,transferable:bool,candidates:list<string>,already_wired:list<string>,scanned:int,note:?string,schema:string}
     */
    private function result(
        string $verdict,
        bool $transferable,
        array $candidates,
        array $alreadyWired,
        int $scanned,
        ?string $note,
    ): array {
        return [
            'verdict' => $verdict,
            'transferable' => $transferable,
            'candidates' => $candidates,
            'already_wired' => $alreadyWired,
            'scanned' => $scanned,
            'note' => $note,
            'schema' => self::SCHEMA,
        ];
    }

    /**
     * @param  array<mixed>  $patterns
     * @return list<string>
     */
    private function normalizedPatterns(array $patterns): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $pattern): string => trim(str_replace('\\', '/', (string) $pattern), '/'),
            $patterns,
        ), static fn (string $pattern): bool => $pattern !== ''));

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private function matchingFiles(string $root, string $pattern): array
    {
        $files = [];
        foreach (glob($root.'/'.$pattern, GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                $files[$file] = $file;
            }
        }

        if (str_contains($pattern, '**')) {
            $regex = $this->patternRegex($pattern);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relative = $this->relativePath($root, $file->getPathname());
                if (preg_match($regex, $relative) === 1) {
                    $files[$file->getPathname()] = $file->getPathname();
                }
            }
        }

        return array_values($files);
    }

    private function patternRegex(string $pattern): string
    {
        $quoted = preg_quote(str_replace('\\', '/', $pattern), '#');
        $quoted = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $quoted);

        return '#^'.$quoted.'$#';
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function containsToken(string $contents, string $shortName): bool
    {
        return preg_match('/\b'.preg_quote($shortName, '/').'\b/', $contents) === 1;
    }

    private function relativePath(string $root, string $file): string
    {
        $relative = substr($file, strlen($root) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
