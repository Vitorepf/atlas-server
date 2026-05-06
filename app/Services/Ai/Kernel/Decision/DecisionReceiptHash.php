<?php

namespace App\Services\Ai\Kernel\Decision;

final class DecisionReceiptHash
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        $payload = self::canonicalize($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonicalize($value);
            }
        }

        return $payload;
    }
}
