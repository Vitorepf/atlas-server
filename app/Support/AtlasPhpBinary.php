<?php

namespace App\Support;

class AtlasPhpBinary
{
    public static function path(): string
    {
        $configured = self::string(config('atlas.cli.php_binary'));
        if ($configured !== '') {
            return $configured;
        }

        foreach ((array) config('atlas.cli.php_binary_candidates', []) as $candidate) {
            $candidate = self::string($candidate);
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return PHP_BINARY !== '' ? PHP_BINARY : 'php';
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
