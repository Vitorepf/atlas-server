<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

use RuntimeException;

/**
 * Append-only ledger of cross-primitive wire-format message receipts. Stores provider-safe rows:
 *   - source         : emitting primitive id (string)
 *   - target         : receiving primitive id (string)
 *   - schema_id      : wire schema id (string)
 *   - payload_hash   : sha256 of the wire payload (string)
 *   - validation_ok  : bool (envelope validation outcome)
 *   - observed_at    : unix seconds (int)
 *   - receipt_hash   : sha256 over the canonical body (sorted keys, sans receipt_hash)
 *
 * INVARIANTS:
 *   - append() never mutates prior rows.
 *   - history() returns newest-first, optionally filtered by source and/or schema_id, bounded by $limit.
 *   - Malformed receipts (missing required fields) are REJECTED with a RuntimeException — never written.
 *   - receipt_hash is byte-stable: identical bodies ⇒ identical hashes (canonical JSON encoding).
 */
final class AtlasLoopInterPrimitiveMessageReceiptLedger
{
    public const REQUIRED_FIELDS = [
        'source', 'target', 'schema_id', 'payload_hash', 'validation_ok', 'observed_at',
    ];

    /** @var list<array<string,mixed>> */
    private array $rows = [];

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt): array
    {
        $this->assertWellFormed($receipt);
        $body = [
            'source' => (string) $receipt['source'],
            'target' => (string) $receipt['target'],
            'schema_id' => (string) $receipt['schema_id'],
            'payload_hash' => (string) $receipt['payload_hash'],
            'validation_ok' => (bool) $receipt['validation_ok'],
            'observed_at' => (int) $receipt['observed_at'],
        ];
        $body['receipt_hash'] = $this->hashBody($body);
        $this->rows[] = $body;

        return $body;
    }

    /**
     * Newest-first bounded history. Optional filter by source and/or schema_id.
     *
     * @param  array{source?: string, schema_id?: string}  $filter
     * @return list<array<string,mixed>>
     */
    public function history(array $filter = [], int $limit = 100): array
    {
        $filtered = array_filter($this->rows, static function (array $row) use ($filter): bool {
            if (isset($filter['source']) && (string) $filter['source'] !== (string) $row['source']) {
                return false;
            }
            if (isset($filter['schema_id']) && (string) $filter['schema_id'] !== (string) $row['schema_id']) {
                return false;
            }

            return true;
        });
        $rows = array_values(array_reverse($filtered));

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function assertWellFormed(array $receipt): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $receipt)) {
                throw new RuntimeException('malformed_receipt:missing_field:'.$field);
            }
        }
        if ((string) $receipt['source'] === '' || (string) $receipt['target'] === '' || (string) $receipt['schema_id'] === '' || (string) $receipt['payload_hash'] === '') {
            throw new RuntimeException('malformed_receipt:empty_identifier');
        }
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function hashBody(array $body): string
    {
        $canonical = $body;
        unset($canonical['receipt_hash']);
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
