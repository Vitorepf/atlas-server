<?php

namespace App\Services\Ai\AutomationDomain;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Deterministic JSON-stable sha256 hash used by every Automation domain
 * artifact (run, plan, decision, evolution event) to make payloads
 * reproducible across machines and replay-able.
 */
class AutomationCanonicalHash
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
