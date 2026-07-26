<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Support;

/**
 * Provider-safe redaction helper per contracts doc 3.4.
 *
 * Redaction never silently drops a field. A sensitive value is replaced
 * by a deterministic `[redacted:<reason>:sha256:<hash>]` token so downstream
 * validators can audit that the original payload existed and where it went.
 */
final class ProviderSafeRedactor
{
    public static function redactScalar(mixed $value, string $reason): string
    {
        $payload = is_scalar($value) || $value === null
            ? (string) ($value ?? '')
            : CanonicalJson::encode(CanonicalJson::canonicalize((array) $value));

        return self::token($reason, $payload);
    }

    public static function redactString(?string $value, string $reason): string
    {
        return self::token($reason, $value ?? '');
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    public static function redactStringList(array $items, string $reason): array
    {
        $redacted = [];
        foreach ($items as $item) {
            $redacted[] = self::token($reason, $item);
        }

        return $redacted;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  list<string>  $sensitiveKeys  keys whose values must be redacted
     * @return list<array<string, mixed>>
     */
    public static function redactArrayOfMaps(array $items, array $sensitiveKeys, string $reason): array
    {
        $redacted = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ($sensitiveKeys as $key) {
                if (array_key_exists($key, $item) && is_string($item[$key])) {
                    $item[$key] = self::token($reason, $item[$key]);
                }
            }
            $redacted[] = $item;
        }

        return $redacted;
    }

    private static function token(string $reason, string $payload): string
    {
        return '[redacted:'.$reason.':sha256:'.hash('sha256', $payload).']';
    }
}
