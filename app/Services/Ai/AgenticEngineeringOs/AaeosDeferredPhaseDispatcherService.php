<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * AAEOS Deferred Phase Dispatcher (AP-799 wiring slice).
 *
 * The HTTP path facade emits envelopes marked `synchronous_invocation: deferred`
 * for phases P5..P9 (topology, routing, spec, tasks, receipt) under Phase 3/4.
 * Those envelopes declare WHAT must happen but do not invoke the canonical
 * runtime synchronously to keep HTTP p95 latency under +20%.
 *
 * This service is the canonical place where the deferred work is parked.
 * It writes each deferred envelope into a JSONL queue file and an in-memory
 * counter, providing:
 *
 *   - durable persistence (file at storage/atlas/aaeos/deferred.jsonl),
 *   - a count of pending dispatches (telemetry),
 *   - an explicit `claim()` API for async workers to pick up the next batch.
 *
 * The actual worker is intentionally NOT bundled here — async workers
 * (Laravel queues, cron, Forge job runner) call `claim()` and act on
 * the returned envelopes. This keeps the dispatcher pure and testable
 * while providing a concrete persistence path (no longer "caller
 * responsibility").
 */
final class AaeosDeferredPhaseDispatcherService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.deferred_phase_dispatch.v1';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Append all envelopes that carry a `deferred` marker to the queue.
     *
     * @param  list<array<string,mixed>>  $envelopes
     * @return array<string,mixed>
     */
    public function enqueueFromFacadeResult(array $envelopes, string $queuePath = ''): array
    {
        $path = $queuePath !== '' ? $queuePath : $this->defaultQueuePath();
        $enqueued = [];
        foreach ($envelopes as $env) {
            if (! $this->isDeferred($env)) {
                continue;
            }
            $record = [
                'schema' => self::SCHEMA_VERSION,
                'dispatch_id' => 'disp-'.Str::ulid()->toBase32(),
                'phase' => (string) ($env['phase_out'] ?? ''),
                'intent_id' => (string) ($env['intent_id'] ?? ''),
                'envelope' => $env,
                'enqueued_at' => gmdate('c'),
            ];
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line !== false) {
                $this->ensureDirectory(dirname($path));
                file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
            }
            $enqueued[] = $record;
            $this->incrementCounter('atlas.aaeos.deferred.enqueued.'.$record['phase']);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'queue_path' => $path,
            'enqueued_count' => count($enqueued),
            'enqueued' => $enqueued,
        ];
    }

    /**
     * Atomically claim up to `$max` pending dispatches from the queue.
     * The claimed records are removed from the queue file.
     *
     * @return list<array<string,mixed>>
     */
    public function claim(int $max = 16, string $queuePath = ''): array
    {
        $path = $queuePath !== '' ? $queuePath : $this->defaultQueuePath();
        if ($max <= 0 || ! is_file($path)) {
            return [];
        }

        $fh = fopen($path, 'r+');
        if ($fh === false) {
            return [];
        }
        if (! flock($fh, LOCK_EX)) {
            fclose($fh);

            return [];
        }
        $contents = stream_get_contents($fh) ?: '';
        $lines = $contents === '' ? [] : explode("\n", trim($contents, "\n"));
        $claimed = [];
        $remaining = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (count($claimed) < $max) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $claimed[] = $decoded;
                    continue;
                }
            }
            $remaining[] = $line;
        }
        // Truncate and rewrite remaining lines.
        ftruncate($fh, 0);
        rewind($fh);
        if ($remaining !== []) {
            fwrite($fh, implode("\n", $remaining)."\n");
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        foreach ($claimed as $record) {
            $phase = (string) ($record['phase'] ?? 'unknown');
            $this->incrementCounter('atlas.aaeos.deferred.claimed.'.$phase);
        }

        return $claimed;
    }

    /**
     * Read pending count without claiming.
     */
    public function pendingCount(string $queuePath = ''): int
    {
        $path = $queuePath !== '' ? $queuePath : $this->defaultQueuePath();
        if (! is_file($path)) {
            return 0;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : count($lines);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function isDeferred(array $envelope): bool
    {
        $outputs = is_array($envelope['outputs'] ?? null) ? $envelope['outputs'] : [];
        foreach ($outputs as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (str_ends_with($key, '_invocation') && $value === 'deferred') {
                return true;
            }
        }

        return false;
    }

    private function defaultQueuePath(): string
    {
        return storage_path('atlas/aaeos/deferred.jsonl');
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    private function incrementCounter(string $key): void
    {
        $current = (int) $this->cache->get($key, 0);
        $this->cache->forever($key, $current + 1);
    }
}
