<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Illuminate\Support\Facades\File;

/**
 * Shared byte-identical helper de-duplicated across this family (writeJson).
 */
trait ProgrammingJsonHelper
{
    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
