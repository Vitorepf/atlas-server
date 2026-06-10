<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusSlugNormalizer
{
    public static function areaIdToken(string $value, string $fallback): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: $fallback;

        return trim($slug, '_-') ?: $fallback;
    }

    public static function areaRefToken(string $value, string $fallback): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_:-]+/', '_', $slug) ?: $fallback;

        return trim($slug, '_') ?: $fallback;
    }

    public static function areaDashToken(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'area';
    }

    public static function alnumSeparatedToken(string $value, string $separator = '-', string $fallback = 'unknown'): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', $separator, trim($value)) ?? '';

        return trim(strtolower($slug), $separator) ?: $fallback;
    }

    public static function lowerSnakeToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    public static function lowerSnakeTokenOrFallback(string $value, string $fallback): string
    {
        return self::lowerSnakeToken($value) ?: $fallback;
    }

    public static function lowerSnakeTokenPreservingBoundary(string $value, string $fallback): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))) ?: '';

        return $slug !== '' ? $slug : $fallback;
    }

    public static function lowerPathComponentToken(string $value, string $fallback): string
    {
        $slug = preg_replace('/[^a-z0-9_.-]+/', '_', strtolower($value)) ?: '';

        return $slug !== '' ? $slug : $fallback;
    }

    public static function lowerFileToken(string $value, string $fallback): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: $fallback;
    }

    public static function lowerUnderscoreToken(
        string $value,
        string $fallback = 'unknown',
        bool $trimInput = true,
        bool $trimBoundaryUnderscores = true,
    ): string {
        $source = $trimInput ? trim($value) : $value;
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($source)) ?? '';
        if ($trimBoundaryUnderscores) {
            $slug = trim($slug, '_');
        }

        return $slug !== '' ? $slug : $fallback;
    }

    public static function spaceDashSnakeToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\s\-]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    public static function unscopedToken(string $value): string
    {
        return self::lowerFileToken($value, 'unscoped');
    }
}
