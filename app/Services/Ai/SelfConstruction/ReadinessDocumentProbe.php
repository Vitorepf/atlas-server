<?php

namespace App\Services\Ai\SelfConstruction;

final class ReadinessDocumentProbe
{
    /**
     * @return array{path: string, exists: bool, line_count: int|null}
     */
    public static function status(string $path): array
    {
        static $cached = [];

        if (isset($cached[$path])) {
            return $cached[$path];
        }

        $absolutePath = base_path($path);
        $exists = is_file($absolutePath);

        $cached[$path] = [
            'path' => $path,
            'exists' => $exists,
            'line_count' => $exists ? count(file($absolutePath, FILE_IGNORE_NEW_LINES)) : null,
        ];

        return $cached[$path];
    }

    public static function content(string $path): string
    {
        $absolutePath = base_path($path);

        return is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';
    }
}
