<?php

namespace App\Services\Ai\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Provides the attachmentPath() helper for CLI provider classes.
 *
 * Origin: Consolidation census 05/07 — 3 byte-identical copies (ClaudeCliProvider,
 * CodexCliProvider, HermesCliProvider) extracted into this shared trait.
 * Divergent copies in other classes, if any, remain local.
 */
trait HasAttachmentPath
{
    private function attachmentPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        if (File::isFile($path)) {
            return realpath($path) ?: $path;
        }

        $storagePrefix = '/app/storage/';
        if (str_starts_with($path, $storagePrefix)) {
            $candidate = storage_path(substr($path, strlen($storagePrefix)));
            if (File::isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $appPrefix = '/app/';
        if (str_starts_with($path, $appPrefix)) {
            $candidate = base_path(substr($path, strlen($appPrefix)));
            if (File::isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
