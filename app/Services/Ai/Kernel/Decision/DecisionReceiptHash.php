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

    /**
     * V3 signs the complete transport envelope except its self-referential
     * receipt_hash. Unlike V2's historical hash payloads, no authority field
     * is selected piecemeal: tenant/principal audience, scope, effect, budget,
     * nonce, revocation head and separation-of-duties remain in one basis.
     *
     * @param  array<string,mixed>  $receipt
     */
    public static function v3FullEnvelopeHash(array $receipt): string
    {
        unset($receipt['receipt_hash']);

        return hash('sha256', json_encode(
            self::canonicalizeV3($receipt),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    public static function v3FullEnvelopeHashMatches(array $receipt): bool
    {
        $provided = $receipt['receipt_hash'] ?? null;

        return is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/', $provided) === 1
            && hash_equals(self::v3FullEnvelopeHash($receipt), $provided);
    }

    private static function canonicalizeV3(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeV3($item);
        }

        return $isList ? array_values($value) : $value;
    }
}
