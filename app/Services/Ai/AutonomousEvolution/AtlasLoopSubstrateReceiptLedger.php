<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * SUBSTRATE RECEIPT LEDGER — append-only journal that consolidates every substrate-sovereignty FACT into one
 * auditable stream at `storage/app/atlas/loop/substrate-ledger/{YYYY-MM-DD}.jsonl`.
 *
 * Each receipt is one substrate event (provider health sample id, swap decision id, fleet autotune snapshot
 * id, workspace floor snapshot id, effort policy id, campaign_id, grind_id), tagged with schema
 * `atlas.loop.substrate_receipt.v1` and a deterministic `receipt_id` = sha256 of the canonical body.
 *
 * APPEND-ONLY INVARIANT (load-bearing):
 *   - The public API is EXACTLY {@see append()} + {@see read()} + the constructor — no update, no delete, no
 *     truncate, no mutation. Each append opens the daily file with `c+` (create-or-append), flocks LOCK_EX,
 *     seeks to end, writes one JSONL line + LF, fflush+fsync, releases. Existing bytes are never overwritten;
 *     the file's pre-write bytes remain a strict prefix of the post-write bytes for every append.
 *   - The flag `atlas.loop.substrate_receipt_ledger_enabled` (default false) is honored upstream by callers
 *     (supervisor + keepalive); the ledger itself does NOT check it. A direct append() call ALWAYS writes —
 *     the flag is enforced at the call-site to keep the gate observable and the ledger pure.
 */
final class AtlasLoopSubstrateReceiptLedger
{
    public const SCHEMA = 'atlas.loop.substrate_receipt.v1';

    public function __construct(private readonly ?string $storageRoot = null)
    {
    }

    /**
     * @param  array<string,mixed>  $receipt  any substrate FACT keyed by its source-id fields (e.g.
     *                                        provider_health_sample_id, swap_decision_id, fleet_snapshot_id,
     *                                        workspace_floor_snapshot_id, effort_policy_id, campaign_id,
     *                                        grind_id). The ledger stamps schema_version, receipt_id, and
     *                                        recorded_at_iso8601 automatically.
     * @return string  the newly-minted receipt_id
     */
    public function append(array $receipt): string
    {
        $recordedAt = gmdate(DATE_ATOM);
        $body = $receipt;
        unset($body['receipt_id'], $body['schema_version'], $body['recorded_at_iso8601']);
        ksort($body);
        $body = self::canonicalize($body);
        $body['recorded_at_iso8601'] = $recordedAt;
        $body['schema_version'] = self::SCHEMA;
        ksort($body);
        $receiptId = hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $body['receipt_id'] = $receiptId;
        ksort($body);

        $path = $this->dayPath($recordedAt);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Substrate ledger cannot open '.$path);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Substrate ledger cannot acquire LOCK_EX');
            }
            fseek($fh, 0, SEEK_END);
            fwrite($fh, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $receiptId;
    }

    /**
     * Walk every daily file whose date falls inside [$sinceEpoch, $untilEpoch] and yield matching receipts.
     * Read-only by definition — never opens for write.
     *
     * @return iterable<array<string,mixed>>
     */
    public function read(int $sinceEpoch, int $untilEpoch): iterable
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return;
        }
        $days = glob($root.'/*.jsonl') ?: [];
        sort($days);
        foreach ($days as $path) {
            $base = basename($path, '.jsonl');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) {
                continue;
            }
            $dayStart = (int) strtotime($base.' 00:00:00 UTC');
            $dayEnd = $dayStart + 86399;
            if ($dayEnd < $sinceEpoch || $dayStart > $untilEpoch) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode((string) $line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $ts = (int) strtotime((string) ($decoded['recorded_at_iso8601'] ?? ''));
                if ($ts < $sinceEpoch || $ts > $untilEpoch) {
                    continue;
                }
                yield $decoded;
            }
        }
    }

    private function dayPath(string $isoTimestamp): string
    {
        $day = substr($isoTimestamp, 0, 10);

        return $this->root().'/'.$day.'.jsonl';
    }

    private function root(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return rtrim($this->storageRoot, '/');
        }
        if (function_exists('storage_path')) {
            try {
                return rtrim((string) storage_path('app/atlas/loop/substrate-ledger'), '/');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas/loop/substrate-ledger';
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
