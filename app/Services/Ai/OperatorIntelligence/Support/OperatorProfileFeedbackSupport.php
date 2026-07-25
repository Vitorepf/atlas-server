<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

/**
 * Pure reverse-handle codec for operator profile feedback (full-pass peel).
 */
final class OperatorProfileFeedbackSupport
{
    private const PREFIX = 'operator-profile-confidence:';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encodeReverseHandle(array $payload): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeReverseHandle(string $handle): array
    {
        if (! str_starts_with($handle, self::PREFIX)) {
            throw new \InvalidArgumentException('Invalid operator profile confidence reverse handle.');
        }

        $encoded = substr($handle, strlen(self::PREFIX));
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (! is_string($json)) {
            throw new \InvalidArgumentException('Invalid operator profile confidence reverse payload.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Invalid operator profile confidence reverse payload.');
        }

        return $payload;
    }
}
