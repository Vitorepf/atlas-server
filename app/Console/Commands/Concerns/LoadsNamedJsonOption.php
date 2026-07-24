<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Throwable;

/**
 * Load a JSON object from a named CLI option path (e.g. --facts=, --packet=).
 *
 * Full-pass reuse for NativeImplementation and similar commands that share
 * emitError() for usage failures.
 *
 * Host must implement emitError(string $status, string $reason): void.
 */
trait LoadsNamedJsonOption
{
    /**
     * @return array<string, mixed>|null
     */
    private function loadJson(string $option): ?array
    {
        $path = (string) $this->option($option);
        if ($path === '' || ! is_file($path)) {
            $this->emitError('usage_error', '--'.$option.'=<path> is required for this action');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->emitError('usage_error', $option.'_payload_not_valid_json');

            return null;
        }
        if (! is_array($decoded)) {
            $this->emitError('usage_error', $option.'_payload_root_must_be_object');

            return null;
        }

        return $decoded;
    }

    abstract private function emitError(string $status, string $reason): void;
}
