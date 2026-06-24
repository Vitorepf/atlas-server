<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * Deterministic hash for one {@see DialogueLedgerEvent} row — sha256 of the canonical row bytes (recursively
 * ksort'd JSON of every field EXCEPT this_row_hash). The row's prev_row_hash IS part of the canonical body,
 * so any mutation anywhere in the history breaks the next row's recompute. This is the chain primitive
 * {@see AtlasMaestroDialogueDrivenPacketLedger::verifyChain()} walks.
 */
final class DialogueLedgerChainHasher
{
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function hashRow(DialogueLedgerEvent $event): string
    {
        return self::hashCanonicalBody($event->canonicalBody());
    }

    /**
     * @param  array<string,mixed>  $body  the row's canonical body (no this_row_hash)
     */
    public static function hashCanonicalBody(array $body): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
