<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\EngineeringKernel\Adapters\ReceiptHashTrait;

final class DecisionReceiptHash
{
    use ReceiptHashTrait {
        canonicalize as private traitCanonicalize;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        $canonical = json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', (string) $canonical);
    }

    /**
     * Explicit façade keeps the runtime contract discoverable by static gates
     * while the shared trait remains the single canonicalization implementation.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        return self::traitCanonicalize($payload);
    }
}
