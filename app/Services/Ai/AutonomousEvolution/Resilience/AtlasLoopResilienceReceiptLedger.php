<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Resilience;

/**
 * RESILIENCE RECEIPT LEDGER — the FACT-BASED memory of Loop fault-recovery. Every detection + action taken by
 * the resilience family (topology probe anomalies, hung-grind verdicts, zombie reaps, respawn decisions, bash
 * watchdog kills) is appended as one durable JSONL line, daily-bucketed under
 * storage/atlas-loop/resilience-ledger/YYYY-MM-DD.jsonl. Post-mortems read the ledger; they do not read vibes.
 *
 * APPEND-ONLY: the event_id is the sha256 of the canonical (ksort-recursive) body — writing the same logical
 * event twice persists exactly one line (the second write is a no-op).
 *
 * DURABLE WRITE: each append acquires an exclusive flock(LOCK_EX) on the day file, writes the line, fflush+
 * fsync the descriptor, then releases — so a crash mid-write leaves the previous content intact and the file
 * recoverable. (We do not need atomic-rename here because we never overwrite, we only append; flock+fsync is
 * the right primitive for append-only JSONL.)
 *
 * READER: query(campaign_id, since_ts, until_ts) walks the day files covering the window, filters by
 * campaign_id (or null to allow all), returns chronologically-ordered events. Output is canonical-key-sorted
 * so fixtures are byte-deterministic.
 *
 * SCOPE: writes EXCLUSIVELY to storage/atlas-loop/resilience-ledger/*.jsonl. Performs no network call, no
 * shell call, no provider call — pure file I/O over the storage path the constructor was handed.
 */
final class AtlasLoopResilienceReceiptLedger
{
    public const SCHEMA_VERSION = 'atlas.loop.resilience_receipt.v1';

    public function __construct(
        private readonly ?string $storageRoot = null,
        private $clock = null,
    ) {
    }

    /**
     * Append (or no-op-if-duplicate) one resilience event. Returns the canonical event id (sha256) so the
     * caller can correlate it with downstream telemetry. The same logical event always maps to the same id.
     *
     * @param  array<string,mixed>  $event
     */
    public function append(array $event): string
    {
        $body = $this->canonicalBody($event);
        $eventId = $this->computeEventId($body);
        $body['event_id'] = $eventId;
        $body['schema_version'] = self::SCHEMA_VERSION;
        ksort($body);

        $recordedAt = $this->now();
        $dayPath = $this->dayPath($body['recorded_at'] ?? $recordedAt);

        $dir = dirname($dayPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($dayPath, 'c+');
        if ($handle === false) {
            return $eventId; // best-effort fail-open: storage error must not crash the loop
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return $eventId;
            }

            if ($this->dayContainsEventId($handle, $eventId)) {
                return $eventId; // no-op: identical event already durable
            }

            fseek($handle, 0, SEEK_END);
            fwrite($handle, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($handle);
            // Best-effort durable sync — some platforms reject fsync on text streams; ignore the boolean.
            @\fsync($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $eventId;
    }

    /**
     * Walk the day files covering [$since, $until] and return matching events in chronological order.
     *
     * @return list<array<string,mixed>>
     */
    public function query(?string $campaignId = null, ?int $since = null, ?int $until = null): array
    {
        $events = [];
        foreach ($this->dayFilesInWindow($since, $until) as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
                $decoded = json_decode((string) $raw, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $ts = (int) ($decoded['recorded_at'] ?? 0);
                if ($since !== null && $ts < $since) {
                    continue;
                }
                if ($until !== null && $ts > $until) {
                    continue;
                }
                if ($campaignId !== null && (string) ($decoded['campaign_id'] ?? '') !== $campaignId) {
                    continue;
                }
                ksort($decoded);
                $events[] = $decoded;
            }
        }

        usort($events, static function (array $x, array $y): int {
            return [(int) ($x['recorded_at'] ?? 0), (string) ($x['event_id'] ?? '')]
                <=> [(int) ($y['recorded_at'] ?? 0), (string) ($y['event_id'] ?? '')];
        });

        return $events;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function canonicalBody(array $event): array
    {
        $body = $event;
        unset($body['event_id'], $body['schema_version']);
        if (! isset($body['recorded_at'])) {
            $body['recorded_at'] = $this->now();
        }

        return self::canonicalize($body);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function computeEventId(array $body): string
    {
        return hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  resource  $handle
     */
    private function dayContainsEventId($handle, string $eventId): bool
    {
        rewind($handle);
        while (($raw = fgets($handle)) !== false) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (str_contains($raw, '"event_id":"'.$eventId.'"')) {
                return true;
            }
        }

        return false;
    }

    private function dayPath(int $ts): string
    {
        return $this->root().'/'.gmdate('Y-m-d', $ts).'.jsonl';
    }

    /**
     * @return list<string>
     */
    private function dayFilesInWindow(?int $since, ?int $until): array
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        foreach (glob($root.'/*.jsonl') ?: [] as $path) {
            $base = basename($path, '.jsonl');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) {
                continue;
            }
            $dayStart = (int) strtotime($base.' 00:00:00 UTC');
            $dayEnd = $dayStart + 86399;
            if ($until !== null && $dayStart > $until) {
                continue;
            }
            if ($since !== null && $dayEnd < $since) {
                continue;
            }
            $paths[] = $path;
        }
        sort($paths);

        return $paths;
    }

    private function root(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return rtrim($this->storageRoot, '/');
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas-loop/resilience-ledger')
            : sys_get_temp_dir().'/atlas-loop/resilience-ledger';

        return rtrim($base, '/');
    }

    private function now(): int
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (int) $clock();
        }

        return time();
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::canonicalize($item);
            }

            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
