<?php

namespace App\Services\Ai\Cyber;

use App\Services\Ai\Mission\MissionCanonicalHash;

class CyberCanonicalHash
{
    public static function sha256(mixed $value): string
    {
        return MissionCanonicalHash::sha256($value);
    }

    public static function canonicalJson(mixed $value): string
    {
        return MissionCanonicalHash::canonicalJson($value);
    }
}
