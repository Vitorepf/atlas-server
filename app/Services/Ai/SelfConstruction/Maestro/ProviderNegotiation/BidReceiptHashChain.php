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
        ksort($body);
        foreach ($body as &$v) {
            if (is_array($v)) {
                ksort($v);
            }
        }
        unset($v);

        return hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function chainLink(string $prevEntrySha, string $bodySha): string
    {
        return hash('sha256', $prevEntrySha.'||'.$bodySha);
    }
}
