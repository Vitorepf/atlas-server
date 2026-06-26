<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

final class ReadinessHash
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function stable(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortRecursive($entry);
            }
        }

        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}