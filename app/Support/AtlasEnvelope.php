<?php

namespace App\Support;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasEnvelope
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function seal(array $payload, string $hashKey): array
    {
        $payload[$hashKey] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
