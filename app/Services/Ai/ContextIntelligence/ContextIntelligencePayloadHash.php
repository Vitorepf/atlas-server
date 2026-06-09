<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class ContextIntelligencePayloadHash
{
    /**
     * Hash a context-intelligence payload while excluding volatile envelope fields.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function forPayload(array $payload, string $hashField): string
    {
        unset($payload['generated_at'], $payload[$hashField]);

        return MissionCanonicalHash::sha256($payload);
    }
}
