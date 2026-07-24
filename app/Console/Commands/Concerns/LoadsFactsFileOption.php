<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Shared --facts-file JSON loader (fail-open empty array).
 *
 * Full-pass reuse for ExternalBrain CLIs that treat missing/unreadable facts as [].
 */
trait LoadsFactsFileOption
{
    /**
     * @return array<string, mixed>
     */
    private function loadFacts(): array
    {
        $path = trim((string) $this->option('facts-file'));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
