<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;

final class AtlasSelfConstructionReservationRepository
{
    public function __construct(
        private readonly ?string $root = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->withLock(function (): array {
            $projection = $this->projection();
            $events = $this->events();

            return [
                'ledger_available' => true,
                'event_count' => count($events),
                'active_count' => count(array_filter($projection, fn (array $reservation): bool => $this->isActive($reservation))),
                'completed_count' => count(array_filter($projection, fn (array $reservation): bool => ($reservation['state'] ?? null) === 'completed')),
                'projection_count' => count($projection),
                'active_reservations' => array_values(array_filter($projection, fn (array $reservation): bool => $this->isActive($reservation))),
                'completed_reservations' => array_values(array_filter($projection, fn (array $reservation): bool => ($reservation['state'] ?? null) === 'completed')),
                'last_event_hash' => data_get($events, (count($events) - 1).'.event_hash'),
                'storage' => [
                    'events_path' => $this->eventsPath(),
                    'projection_path' => $this->projectionPath(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function claim(array $packet, string $actor, string $session, int $leaseMinutes, string $packetHash): array
    {
        return $this->withLock(function () use ($packet, $actor, $session, $leaseMinutes, $packetHash): array {
            $projection = $this->projection();
            $packetId = (string) data_get($packet, 'packet_id');
            $allowedFiles = array_values((array) data_get($packet, 'allowed_files', []));
            $forbiddenFiles = array_values((array) data_get($packet, 'forbidden_files', []));

            $blocking = $this->claimBlockers($packetId, $allowedFiles, $forbiddenFiles, $projection);
            if ($blocking !== []) {
                return [
                    'status' => 'blocked',
                    'reservation' => null,
                    'blocking_reasons' => $blocking,
                    'ledger_write_allowed' => true,
                    'event_appended' => false,
                ];
            }

            $now = now();
            $reservation = [
                'reservation_id' => 'RES-'.strtoupper(substr(hash('sha256', $packetId.'|'.$actor.'|'.$session.'|'.$now->toIso8601String()), 0, 20)),
                'packet_id' => $packetId,
                'actor' => $actor,
                'session' => $session,
                'state' => 'claimed',
                'packet_hash' => $packetHash,
                'allowed_files' => $allowedFiles,
                'allowed_files_hash' => $this->stableHash(['allowed_files' => $allowedFiles]),
                'claimed_at' => $now->toIso8601String(),
                'lease_expires_at' => $now->copy()->addMinutes(max(1, $leaseMinutes))->toIso8601String(),
                'released_at' => null,
                'completed_at' => null,
                'release_reason' => null,
            ];

            $event = $this->appendEvent('claimed', $reservation, [
                'actor' => $actor,
                'session' => $session,
                'lease_minutes' => max(1, $leaseMinutes),
            ]);

            $projection[$packetId] = $reservation;
            $this->writeProjection($projection);

            return [
                'status' => 'claimed',
                'reservation' => $reservation,
                'event' => $event,
                'blocking_reasons' => [],
                'ledger_write_allowed' => true,
                'event_appended' => true,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function release(string $packetId, string $actor, string $session, string $reason): array
    {
        return $this->withLock(function () use ($packetId, $actor, $session, $reason): array {
            $projection = $this->projection();
            $reservation = $projection[$packetId] ?? null;

            if (! is_array($reservation) || ! $this->isActive($reservation)) {
                return [
                    'status' => 'blocked',
                    'released' => false,
                    'blocking_reasons' => ['reservation_not_active'],
                    'event_appended' => false,
                ];
            }

            if (($reservation['actor'] ?? null) !== $actor || ($reservation['session'] ?? null) !== $session) {
                return [
                    'status' => 'blocked',
                    'released' => false,
                    'blocking_reasons' => ['actor_or_session_not_owner'],
                    'event_appended' => false,
                ];
            }

            $reservation['state'] = 'released';
            $reservation['released_at'] = now()->toIso8601String();
            $reservation['release_reason'] = $reason;

            $event = $this->appendEvent('released', $reservation, [
                'actor' => $actor,
                'session' => $session,
                'reason' => $reason,
            ]);

            $projection[$packetId] = $reservation;
            $this->writeProjection($projection);

            return [
                'status' => 'released',
                'released' => true,
                'reservation' => $reservation,
                'event' => $event,
                'blocking_reasons' => [],
                'event_appended' => true,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(string $packetId, string $actor, string $session, string $reason, ?string $evidenceHash = null): array
    {
        return $this->withLock(function () use ($packetId, $actor, $session, $reason, $evidenceHash): array {
            $projection = $this->projection();
            $reservation = $projection[$packetId] ?? null;

            if (! is_array($reservation) || ! $this->isActive($reservation)) {
                return [
                    'status' => 'blocked',
                    'completed' => false,
                    'blocking_reasons' => ['reservation_not_active'],
                    'event_appended' => false,
                ];
            }

            if (($reservation['actor'] ?? null) !== $actor || ($reservation['session'] ?? null) !== $session) {
                return [
                    'status' => 'blocked',
                    'completed' => false,
                    'blocking_reasons' => ['actor_or_session_not_owner'],
                    'event_appended' => false,
                ];
            }

            $reservation['state'] = 'completed';
            $reservation['completed_at'] = now()->toIso8601String();
            $reservation['completion_reason'] = $reason;
            $reservation['completion_evidence_hash'] = $evidenceHash;

            $event = $this->appendEvent('completed', $reservation, [
                'actor' => $actor,
                'session' => $session,
                'reason' => $reason,
                'evidence_hash' => $evidenceHash,
            ]);

            $projection[$packetId] = $reservation;
            $this->writeProjection($projection);

            return [
                'status' => 'completed',
                'completed' => true,
                'reservation' => $reservation,
                'event' => $event,
                'blocking_reasons' => [],
                'event_appended' => true,
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function activeByPacket(): array
    {
        return $this->withLock(function (): array {
            return array_filter($this->projection(), fn (array $reservation): bool => $this->isActive($reservation));
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function completedByPacket(): array
    {
        return $this->withLock(function (): array {
            return array_filter($this->projection(), fn (array $reservation): bool => ($reservation['state'] ?? null) === 'completed');
        });
    }

    /**
     * @param  array<string, mixed>  $reservation
     */
    private function isActive(array $reservation): bool
    {
        if (! in_array($reservation['state'] ?? null, ['claimed', 'renewed'], true)) {
            return false;
        }

        $expiresAt = strtotime((string) ($reservation['lease_expires_at'] ?? ''));

        return $expiresAt !== false && $expiresAt > time();
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  array<string, array<string, mixed>>  $projection
     * @return list<string>
     */
    private function claimBlockers(string $packetId, array $allowedFiles, array $forbiddenFiles, array $projection): array
    {
        $blockers = [];

        if ($packetId === '') {
            $blockers[] = 'packet_id_missing';
        }

        if ($this->containsHotScope($allowedFiles) || $this->containsHotScope($forbiddenFiles, true)) {
            $blockers[] = 'hot_scope_forbidden';
        }

        $current = $projection[$packetId] ?? null;
        if (is_array($current) && $this->isActive($current)) {
            $blockers[] = 'packet_already_claimed';
        }
        if (is_array($current) && ($current['state'] ?? null) === 'completed') {
            $blockers[] = 'packet_already_completed';
        }

        foreach ($projection as $reservation) {
            if (! $this->isActive($reservation)) {
                continue;
            }

            $overlap = WriteSetOverlap::collidingPaths($allowedFiles, (array) ($reservation['allowed_files'] ?? [])); // A5/MF-12: prefix-aware dir-vs-file
            if ($overlap !== []) {
                $blockers[] = 'allowed_files_overlap_active_reservation';
                break;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $paths
     */
    private function containsHotScope(array $paths, bool $forbiddenList = false): bool
    {
        foreach ($paths as $raw) {
            // Normalize path: backslash → '/', strip leading './', collapse '//'.
            $path = str_replace('\\', '/', $raw);
            if (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }
            $path = (string) preg_replace('#/{2,}#', '/', $path);
            $isHot = str_starts_with($path, 'runtimes/python/voice_realtime/')
                || $path === 'runtimes/python/voice_realtime/**'
                || str_starts_with($path, 'app/Services/Ai/Voice/')
                || $path === 'app/Services/Ai/Voice/**'
                || $path === 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php'
                || $path === 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md';

            if ($isHot && ! $forbiddenList) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        if (! is_file($this->eventsPath())) {
            return [];
        }

        $events = [];
        foreach (file($this->eventsPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function projection(): array
    {
        if (! is_file($this->projectionPath())) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->projectionPath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $projection
     */
    private function writeProjection(array $projection): void
    {
        $this->ensureDirectory();

        file_put_contents(
            $this->projectionPath(),
            json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appendEvent(string $type, array $reservation, array $payload): array
    {
        $this->ensureDirectory();

        $events = $this->events();
        $previousHash = data_get($events, (count($events) - 1).'.event_hash');
        $event = [
            'event_id' => 'EVT-'.strtoupper(substr(hash('sha256', $type.'|'.json_encode($reservation).'|'.microtime(true)), 0, 20)),
            'event_type' => $type,
            'reservation_id' => $reservation['reservation_id'] ?? null,
            'packet_id' => $reservation['packet_id'] ?? null,
            'actor' => $payload['actor'] ?? null,
            'session' => $payload['session'] ?? null,
            'previous_event_hash' => $previousHash,
            'payload' => $payload,
            'reservation_snapshot' => $reservation,
            'created_at' => now()->toIso8601String(),
        ];
        $event['event_hash'] = $this->stableHash($event);

        file_put_contents(
            $this->eventsPath(),
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return $event;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $this->ensureDirectory();

        $lock = fopen($this->lockPath(), 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open Self-Construction reservation lock file.');
        }

        try {
            flock($lock, LOCK_EX);

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create Self-Construction reservation directory.');
        }
    }

    private function directory(): string
    {
        return $this->root ?: storage_path('app/atlas/self-construction/reservations');
    }

    private function eventsPath(): string
    {
        return $this->directory().'/events.jsonl';
    }

    private function projectionPath(): string
    {
        return $this->directory().'/projection.json';
    }

    private function lockPath(): string
    {
        return $this->directory().'/ledger.lock';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
