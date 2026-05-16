<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Support;

final class CanonicalHasher
{
    public const ALGO = 'sha256';

    public static function hash(array $payload): string
    {
        return hash(self::ALGO, CanonicalJson::encode($payload));
    }

    public static function hashWithout(array $payload, string $excludedKey): string
    {
        return hash(self::ALGO, CanonicalJson::encodeWithout($payload, $excludedKey));
    }
}
