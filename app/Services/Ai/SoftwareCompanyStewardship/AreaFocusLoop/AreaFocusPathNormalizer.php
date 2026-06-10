<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusPathNormalizer
{
    public static function repoRelativeNoWhitespace(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('/\s+/', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    public static function stripLeadingDotSlash(string $path, string $trimCharacters = " \t\n\r\0\x0B"): string
    {
        $path = trim($path, $trimCharacters);
        if (str_starts_with($path, './')) {
            return substr($path, 2);
        }

        return $path;
    }

    public static function existingOrRawPathWithoutDeletedSuffix(string $path): string
    {
        $trimmed = trim($path);
        $normalized = preg_replace('/\s+\(deleted\)$/', '', $trimmed) ?: $trimmed;
        if ($normalized === '') {
            return '';
        }

        return realpath($normalized) ?: $normalized;
    }

    /**
     * @return list<string>
     */
    public static function trimmedRepoRelativeUniqueStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $path) {
            if (! is_string($path)) {
                continue;
            }

            $candidate = ltrim(str_replace('\\', '/', trim($path)), '/');
            if ($candidate !== '' && ! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    public static function relativeToBasePath(string $path): string
    {
        $base = rtrim(self::basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $base !== DIRECTORY_SEPARATOR && str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }

    public static function repoRelativeFromBasePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', self::basePath()), '/');
        if ($base !== '' && str_starts_with($normalized, $base.'/')) {
            return ltrim(substr($normalized, strlen($base) + 1), '/');
        }

        return ltrim($normalized, '/');
    }

    public static function absoluteFromBasePath(string $path): string
    {
        return rtrim(self::basePath(), '/').'/'.ltrim($path, '/');
    }

    private static function basePath(): string
    {
        if (function_exists('base_path')) {
            return (string) base_path();
        }

        return getcwd() ?: '';
    }
}
