<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Throwable;

/**
 * Shared silent JSON option parser (inline JSON or @path).
 *
 * Full-pass reuse: byte-identical private resolveJsonOption() copies across
 * Self-Improvement CLI commands that fail closed without components->error.
 */
trait ResolvesSilentJsonOption
{
    /**
     * @return array<string, mixed>|null
     */
    private function resolveJsonOption(string $key): ?array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
