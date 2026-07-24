<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Load a JSON array index from a filesystem disk path (fail-soft → []).
 *
 * Full-pass reuse: de-duplicates private loadIndex() on AgentRuntimeRegistry* repositories.
 *
 * @return list<mixed>
 */
final class DiskJsonIndexLoader
{
    public static function load(Filesystem $disk, string $path): array
    {
        if (! $disk->exists($path)) {
            return [];
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }

        return array_values($decoded);
    }
}
