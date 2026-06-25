<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting;

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

        $dir = \dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return $this->rejection('mkdir_failed', ['dir' => $dir]);
        }

        $fh = @fopen($this->path, 'cb+');
        if (! is_resource($fh)) {
            return $this->rejection('open_failed');
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return $this->rejection('lock_failed');
            }

            $seq = $this->maxSeqIn($fh) + 1;
            $payloadDigest = hash('sha256', (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $record = [
                'seq' => $seq,
                'event_type' => $eventType,
                'parent_cycle_id' => $parentCycleId,
                'child_cycle_id' => $childCycleId,
                'depth' => $depth,
                'recorded_at' => $recordedAt,
                'payload_digest' => $payloadDigest,
            ];
            $line = (string) json_encode($this->sortRecursive($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            fseek($fh, 0, SEEK_END);
            if (fwrite($fh, $line."\n") === false) {
                return $this->rejection('write_failed');
            }
            @fflush($fh);

            return $record;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function chain(string $parentCycleId): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded) || (string) ($decoded['parent_cycle_id'] ?? '') !== $parentCycleId) {
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
     * @param  resource  $fh
     */
    private function maxSeqIn($fh): int
    {
        rewind($fh);
        $max = 0;
        while (($line = fgets($fh)) !== false) {
            $decoded = json_decode((string) trim($line), true);
            if (is_array($decoded) && (int) ($decoded['seq'] ?? 0) > $max) {
                $max = (int) $decoded['seq'];
            }
        }

        return $max;
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
