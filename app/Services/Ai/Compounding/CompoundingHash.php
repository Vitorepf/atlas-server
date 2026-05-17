<?php

namespace App\Services\Ai\Compounding;

final class CompoundingHash
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public static function make(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
