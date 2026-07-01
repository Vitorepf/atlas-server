<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Helper that computes the hash-chain link sha256 between two ledger entries.
 */
final class BidReceiptHashChain
{
    /**
     * @param  array<string,mixed>  $body  canonical entry body (without entry_sha256 and prev_entry_sha256)
     */
    public function bodyHash(array $body): string
    {
        $canonical = $this->canonicalize($body);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function chainLink(string $prevEntrySha, string $bodySha): string
    {
        return hash('sha256', $prevEntrySha.'||'.$bodySha);
    }

    /**
     * Recursively deep-sorts associative array keys (so key order never affects the hash) and
     * normalizes stdClass objects to arrays. Resources and closures are unsafe, non-canonical
     * fields — rejected outright rather than silently hashed inconsistently.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (is_resource($value) || $value instanceof \Closure) {
            throw new \InvalidArgumentException('bid_receipt_hash_chain_unsafe_field:'.get_debug_type($value));
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->canonicalize($v);
            }
            ksort($out);

            return $out;
        }

        return $value;
    }
}
