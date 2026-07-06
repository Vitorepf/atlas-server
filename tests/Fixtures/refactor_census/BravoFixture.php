<?php

declare(strict_types=1);

namespace Tests\Fixtures\RefactorCensus;

final class BravoFixture
{
    public function hydratePayload(array $input): array
    {
        $payload = [];
        foreach ($input as $key => $value) {
            $payload[strtolower((string) $key)] = is_array($value) ? array_values($value) : $value;
        }
        $payload["hydrated_at"] = "2026-01-01";
        $payload["count"] = count($payload);
        ksort($payload);
        return $payload;
    }

    public function tinyPair(int $n): int
    {
        $n = $n + 1;
        $n = $n * 2;
        return $n;
    }
}
