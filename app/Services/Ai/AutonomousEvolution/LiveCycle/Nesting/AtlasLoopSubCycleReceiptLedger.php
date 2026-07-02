<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only ledger of parent↔child sub-cycle events (SPAWN, MERGE, CLOSE). One JSONL line per
 * record; receipts are never updated or deleted. Reconstructible purely from the file — no
 * in-memory state survives between calls. Invalid append (depth<0 or empty child_cycle_id) ⇒
 * returns a RejectionRecord and writes NO line.
 */
final class AtlasLoopSubCycleReceiptLedger
{
    public const EVENT_SPAWN = 'SPAWN';

    public const EVENT_MERGE = 'MERGE';

    public const EVENT_CLOSE = 'CLOSE';

    public const VALID_EVENTS = [self::EVENT_SPAWN, self::EVENT_MERGE, self::EVENT_CLOSE];

    public function __construct(private readonly string $path) {}

    /**
     * @param  array<string,mixed>  $payload  optional opaque payload, hashed into payload_digest
     * @return array<string,mixed>  either an append receipt or a RejectionRecord
     */
    public function append(
        string $eventType,
        string $parentCycleId,
        string $childCycleId,
        int $depth,
        string $recordedAt,
        array $payload = [],
    ): array {
        if (! in_array($eventType, self::VALID_EVENTS, true)) {
            return $this->rejection('invalid_event_type', compact('eventType'));
        }
        if ($childCycleId === '') {
            return $this->rejection('empty_child_cycle_id');
        }
        if ($depth < 0) {
            return $this->rejection('negative_depth', ['depth' => $depth]);
        }
        if ($parentCycleId === '') {
            return $this->rejection('empty_parent_cycle_id');
        }

        $store = new JsonlReceiptStore($this->path);
        $record = null;
        try {
            // Seq derivation happens INSIDE the exclusive write lock (no read-then-write race).
            $store->appendWith(function (?string $lastLine) use ($store, $eventType, $parentCycleId, $childCycleId, $depth, $recordedAt, $payload, &$record): array {
                $max = 0;
                foreach ($store->replay() as $row) {
                    $max = max($max, (int) ($row['seq'] ?? 0));
                }
                $payloadDigest = hash('sha256', (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $record = [
                    'seq' => $max + 1,
                    'event_type' => $eventType,
                    'parent_cycle_id' => $parentCycleId,
                    'child_cycle_id' => $childCycleId,
                    'depth' => $depth,
                    'recorded_at' => $recordedAt,
                    'payload_digest' => $payloadDigest,
                ];

                return $this->sortRecursive($record);
            });
        } catch (\RuntimeException $e) {
            $reason = str_contains($e->getMessage(), 'mkdir') ? 'mkdir_failed'
                : (str_contains($e->getMessage(), 'lock') ? 'lock_failed' : 'open_failed');

            return $this->rejection($reason);
        }

        return $record;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function chain(string $parentCycleId): array
    {
        $rows = [];
        foreach ((new JsonlReceiptStore($this->path))->replay() as $decoded) {
            if ((string) ($decoded['parent_cycle_id'] ?? '') !== $parentCycleId) {
                continue;
            }
            $rows[] = [
                'seq' => (int) ($decoded['seq'] ?? 0),
                'event_type' => (string) ($decoded['event_type'] ?? ''),
                'child_cycle_id' => (string) ($decoded['child_cycle_id'] ?? ''),
                'depth' => (int) ($decoded['depth'] ?? 0),
                'recorded_at' => (string) ($decoded['recorded_at'] ?? ''),
                'payload_digest' => (string) ($decoded['payload_digest'] ?? ''),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array{outcome:string,reason:string,facts:array<string,mixed>}
     */
    private function rejection(string $reason, array $facts = []): array
    {
        return ['outcome' => 'rejected', 'reason' => $reason, 'facts' => $facts];
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->sortRecursive($v);
            }
        }

        return $value;
    }
}
