<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

final class ProviderRuntimeOutput
{
    /**
     * @param  list<string>  $extraSecretPatterns
     */
    public static function redact(string $value, array $extraSecretPatterns = []): string
    {
        if ($value === '') {
            return '';
        }

        $redacted = (string) preg_replace(
            '/(sk-[a-zA-Z0-9_\-]{8,})/',
            'sk-***redacted***',
            $value,
        );

        $patterns = array_merge($extraSecretPatterns, [
            '/(api[_-]?key["\']?\s*[:=]\s*["\']?)[^"\'\s,]+/i',
            '/(bearer\s+)[A-Za-z0-9._\-]+/i',
        ]);

        return (string) preg_replace(
            $patterns,
            array_fill(0, count($patterns), '$1***redacted***'),
            $redacted,
        );
    }

    public static function excerpt(string $value, int $maxLength): string
    {
        if ($value === '' || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'...';
    }
}
