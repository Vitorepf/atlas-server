<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Self-construction signal detector — "what should Atlas improve about itself?".
 *
 * Scans the Atlas codebase for CONCRETE, actionable improvement signals and turns
 * each into a natural-language request the Mission e2e pipe can act on. The first
 * real source is TODO/FIXME markers (deterministic, file-anchored); operator- or
 * registry-supplied gaps can be merged in via the $extraRequests param so the loop
 * is never limited to comments alone. Read-only; deterministic ordering.
 */
final class AtlasSelfConstructionDetector
{
    private const MARKER = '/(?:\/\/|#|\*)\s*(TODO|FIXME)\b\(?\)?:?\s*(.*)$/i';

    private const SCAN_DIRS = ['app', 'runtimes/python/code_graph'];

    private const EXTS = ['php', 'py'];

    private const SKIP = ['/vendor/', '/node_modules/', '/.venv/', '/__pycache__/'];

    /**
     * @param  array<int,string>  $extraRequests  operator/registry improvement requests merged in
     * @return array<int,array{area:string,file:?string,line:?int,signal:string,request:string,source:string}>
     */
    public function detect(string $root, int $max = 5, array $extraRequests = []): array
    {
        $root = rtrim($root, '/');
        $signals = [];

        foreach ($extraRequests as $req) {
            $req = trim((string) $req);
            if ($req !== '') {
                $signals[] = ['area' => 'operator', 'file' => null, 'line' => null, 'signal' => $req, 'request' => $req, 'source' => 'operator_gap'];
            }
        }

        foreach (self::SCAN_DIRS as $dir) {
            $base = $root.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    $path = $file->getPathname();
                    if (! in_array(strtolower($file->getExtension()), self::EXTS, true)) {
                        continue;
                    }
                    foreach (self::SKIP as $skip) {
                        if (str_contains($path, $skip)) {
                            continue 2;
                        }
                    }
                    if ($file->getSize() > 600000) {
                        continue;
                    }
                    $rel = ltrim(str_replace($root, '', $path), '/');
                    $lineNo = 0;
                    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) ?: [] as $line) {
                        $lineNo++;
                        if (preg_match(self::MARKER, $line, $m) === 1) {
                            $text = trim($m[2]) !== '' ? trim($m[2]) : 'address this marker';
                            $signals[] = [
                                'area' => 'code',
                                'file' => $rel,
                                'line' => $lineNo,
                                'signal' => strtoupper($m[1]).': '.$text,
                                'request' => sprintf('Resolve the %s at %s:%d — %s. Keep the change minimal and self-contained.', strtoupper($m[1]), $rel, $lineNo, $text),
                                'source' => 'code_marker',
                            ];
                        }
                    }
                }
            } catch (Throwable) {
                // a scan hiccup must not break detection — return what was found.
            }
        }

        // Deterministic order: operator gaps first, then by file+line.
        usort($signals, static function (array $a, array $b): int {
            if ($a['source'] !== $b['source']) {
                return $a['source'] === 'operator_gap' ? -1 : 1;
            }

            return [($a['file'] ?? ''), ($a['line'] ?? 0)] <=> [($b['file'] ?? ''), ($b['line'] ?? 0)];
        });

        return array_slice($signals, 0, max(1, $max));
    }
}
