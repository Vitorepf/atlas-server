<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Throwable;

/**
 * Shared --facts=<path> JSON object loader for Self-Construction / External-Brain read-only CLIs.
 *
 * Full-pass reuse: byte-identical loadFacts() copies were duplicated across many commands.
 * Requires the host Command to expose option()/line()/error() (Illuminate Console Command).
 */
trait LoadsFactsJsonOption
{
    /**
     * @return array<string, mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->refuseUsage('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->refuseUsage('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->refuseUsage('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    private function refuseUsage(string $reason): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->error($reason);
    }
}
