<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\JsonFileStore;

final class ProviderRuntimeManifestStore
{
    /**
     * @param  array<string,mixed>  $manifest
     */
    public static function write(string $cacheDir, array $manifest): string
    {
        return JsonFileStore::writeTemporary(
            $cacheDir,
            'manifest',
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            0750,
            0640,
        );
    }
}
