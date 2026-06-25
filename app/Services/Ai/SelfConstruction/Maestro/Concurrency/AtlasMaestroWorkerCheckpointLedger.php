<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

use Closure;
use Throwable;

/**
 * Append-only per-worker progress journal at storage/app/atlas/maestro/checkpoints/<client_id>.jsonl. One JSON
 * line per progress mark with monotonic `sequence` + `wall_clock` + `task_packet_id` + opaque `payload_hash`.
 *
 *   record(clientId, taskPacketId, payloadHash)        — append-only; never rewrites prior bytes.
 *   latest(clientId)                                   — most recent entry (highest sequence) for that worker.
 *   resumeFrom(clientId)                               — most recent entry whose task is NOT in the released
 *                                                        leases set, so a restart picks up exactly where it
 *                                                        left off (and doesn't try to "resume" a finished task).
 *
 * Closes the gap that today a killed worker has no durable mark of where it stopped and the serving service
 * picks a fresh packet rather than resuming.
 */
final class AtlasMaestroWorkerCheckpointLedger
{
    private const RELATIVE_DIR = 'app/atlas/maestro/checkpoints';

    private ?Closure $clock = null;

    /** @var callable(string $clientId):iterable<string> */
    private $releasedLeasesSource;

    /**
     * @param  callable(string $clientId):iterable<string>|null  $releasedLeasesSource  fn(client) → released task ids
     */
    public function __construct(
        private readonly ?string $rootOverride = null,
        ?callable $releasedLeasesSource = null,
    ) {
        $this->releasedLeasesSource = $releasedLeasesSource ?? static fn (): iterable => [];
    }

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function path(string $clientId): string
    {
        $root = $this->rootOverride ?? storage_path(self::RELATIVE_DIR);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($clientId)) ?: 'unknown';

        return rtrim($root, '/').'/'.$safe.'.jsonl';
    }

    public function record(string $clientId, string $taskPacketId, string $payloadHash): void
    {
        $path = $this->path($clientId);
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $last = $this->latest($clientId);
        $nextSeq = $last !== null ? ((int) $last['sequence']) + 1 : 1;

        $row = [
            'sequence' => $nextSeq,
            'wall_clock' => $this->now(),
            'client_id' => trim($clientId),
            'task_packet_id' => trim($taskPacketId),
            'payload_hash' => trim($payloadHash),
        ];

        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(string $clientId): ?array
    {
        $rows = $this->all($clientId);
        if ($rows === []) {
            return null;
        }

        return $rows[count($rows) - 1];
    }

    /**
     * Most recent entry whose task_packet_id is NOT in the released-leases set for this worker.
     *
     * @return array<string,mixed>|null
     */
    public function resumeFrom(string $clientId): ?array
    {
        $released = $this->releasedSet($clientId);
        $rows = $this->all($clientId);
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (! isset($released[(string) ($rows[$i]['task_packet_id'] ?? '')])) {
                return $rows[$i];
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function all(string $clientId): array
    {
        $path = $this->path($clientId);
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,true>
     */
    private function releasedSet(string $clientId): array
    {
        $set = [];
        try {
            foreach (($this->releasedLeasesSource)($clientId) as $id) {
                $set[(string) $id] = true;
            }
        } catch (Throwable) {
            // best-effort: a broken source simply yields an empty set ⇒ latest entry resumes.
        }

        return $set;
    }

    private function now(): string
    {
        if ($this->clock !== null) {
            return (string) ($this->clock)();
        }

        return function_exists('now') ? (string) now('UTC')->toIso8601String() : '1970-01-01T00:00:00+00:00';
    }
}
