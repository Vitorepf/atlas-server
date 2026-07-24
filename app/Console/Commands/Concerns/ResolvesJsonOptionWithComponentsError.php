<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Throwable;

/**
 * JSON option parser (inline or @path) that surfaces components->error on missing file.
 *
 * Sibling of ResolvesSilentJsonOption for Self-Improvement CLIs that want operator feedback.
 * Override jsonOptionMissingFileLabel() when the error prefix must differ per command.
 */
trait ResolvesJsonOptionWithComponentsError
{
    protected function jsonOptionMissingFileLabel(): string
    {
        return 'file not found';
    }

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
                $this->components->error($this->jsonOptionMissingFileLabel().': '.$path);

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
