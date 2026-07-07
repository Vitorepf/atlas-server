<?php

declare(strict_types=1);

namespace App\Support;

/**
 * P4 (Obra #19) — per-worker isolation of a shared FILE path under paratest. Given a
 * path and the worker's TEST_TOKEN, returns a token-suffixed path so N parallel
 * workers never write the same file. A file keeps its extension (`foo.jsonl` →
 * `foo-t3.jsonl`); a directory gets a per-token subdir (`foo/bar` → `foo/bar/t3`).
 * An empty path or token returns the path unchanged (serial run = no-op).
 */
final class ParatestPathIsolation
{
    public static function isolate(string $path, string $token): string
    {
        if ($path === '' || $token === '') {
            return $path;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $ext !== ''
            ? substr($path, 0, -(strlen($ext) + 1)).'-t'.$token.'.'.$ext
            : rtrim($path, '/').'/t'.$token;
    }
}
