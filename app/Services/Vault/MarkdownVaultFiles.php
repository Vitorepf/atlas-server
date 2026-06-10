<?php

declare(strict_types=1);

namespace App\Services\Vault;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MarkdownVaultFiles
{
    public static function read(string $path): ?string
    {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    public static function modifiedAt(string $path): int
    {
        return @filemtime($path) ?: 0;
    }

    /**
     * @return Generator<int, string>
     */
    public static function walk(string $dir, bool $skipObsidianInternals = false): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $name = $file->getFilename();
            if ($skipObsidianInternals && (str_starts_with($name, '.') || str_contains($file->getPathname(), '/.obsidian/'))) {
                continue;
            }

            yield $file->getPathname();
        }
    }
}
