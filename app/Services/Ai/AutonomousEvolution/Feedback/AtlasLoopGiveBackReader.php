<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Feedback;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;

final class AtlasLoopGiveBackReader
{
    /** @var array{records_scanned:int, outcomes_emitted:int, malformed_records_skipped:int, malformed_outcomes_skipped:int, storage_mode:string, storage_prefix:string}|null */
    private ?array $lastStats = null;

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
    ) {}

    /**
     * @return list<array{
     *   packet_id:string,
     *   packet_class:string,
     *   outcome:'completed'|'cancelled'|'give_back',
     *   reason:string,
     *   worker:string,
     *   recorded_at:string
     * }>
     */
    public function recent(int $limit = 50): array
    {
        if ($limit <= 0) {
            $this->lastStats = $this->scan()['stats'];

            return [];
        }

        $scan = $this->scan();

        return array_slice($scan['outcomes'], 0, $limit);
    }

    /**
     * @return list<array{
     *   packet_id:string,
     *   packet_class:string,
     *   outcome:'completed'|'cancelled'|'give_back',
     *   reason:string,
     *   worker:string,
     *   recorded_at:string
     * }>
     */
    public function read(int $limit = 50): array
    {
        return $this->recent($limit);
    }

    /**
     * @return array{
     *   records_scanned:int,
     *   outcomes_emitted:int,
     *   malformed_records_skipped:int,
     *   malformed_outcomes_skipped:int,
     *   storage_mode:string,
     *   storage_prefix:string
     * }
     */
    public function stats(): array
    {
        return $this->lastStats ?? $this->scan()['stats'];
    }

    /**
     * @return array{
     *   outcomes:list<array{packet_id:string, packet_class:string, outcome:'completed'|'cancelled'|'give_back', reason:string, worker:string, recorded_at:string}>,
     *   stats:array{records_scanned:int, outcomes_emitted:int, malformed_records_skipped:int, malformed_outcomes_skipped:int, storage_mode:string, storage_prefix:string}
     * }
     */
    private function scan(): array
    {
        $queue = $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
        $registry = $queue->registry();
        $entries = is_array($registry['entries'] ?? null) ? $registry['entries'] : [];

        $recordsScanned = 0;
        $malformedRecords = 0;
        $malformedOutcomes = 0;
        $outcomes = [];

        foreach ($entries as $entry) {
            $taskPacketId = trim((string) ($entry['task_packet_id'] ?? ''));
            if ($taskPacketId === '') {
                $malformedRecords++;

                continue;
            }

            $recordsScanned++;
            $record = $queue->get($taskPacketId);
            if (! is_array($record) || $this->recordMalformed($record, $taskPacketId)) {
                $malformedRecords++;

                continue;
            }

            foreach ($this->recordOutcomes($record, $taskPacketId) as $outcome) {
                if ($outcome === null) {
                    $malformedOutcomes++;

                    continue;
                }

                $outcomes[] = $outcome;
            }
        }

        usort(
            $outcomes,
            static fn (array $left, array $right): int => strcmp($right['recorded_at'], $left['recorded_at'])
        );

        $this->lastStats = [
            'records_scanned' => $recordsScanned,
            'outcomes_emitted' => count($outcomes),
            'malformed_records_skipped' => $malformedRecords,
            'malformed_outcomes_skipped' => $malformedOutcomes,
            'storage_mode' => AgentControlPlaneTaskPacketQueueRepository::MODE,
            'storage_prefix' => AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX,
        ];

        return [
            'outcomes' => $outcomes,
            'stats' => $this->lastStats,
        ];
    }

    private function recordMalformed(mixed $record, string $taskPacketId): bool
    {
        if (! is_array($record)) {
            return true;
        }

        if ((bool) ($record['corrupt'] ?? false)) {
            return true;
        }

        return trim((string) ($record['task_packet_id'] ?? '')) !== $taskPacketId;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{packet_id:string, packet_class:string, outcome:'completed'|'cancelled'|'give_back', reason:string, worker:string, recorded_at:string}|null>
     */
    private function recordOutcomes(array $record, string $taskPacketId): array
    {
        $outcomes = [];

        foreach ((array) ($record['history'] ?? []) as $history) {
            if (! is_array($history)) {
                $outcomes[] = null;

                continue;
            }

            $event = trim((string) ($history['event'] ?? ''));
            $recordedAt = trim((string) ($history['at'] ?? ''));
            $metadata = is_array($history['metadata'] ?? null) ? $history['metadata'] : [];
            $to = trim((string) ($history['to'] ?? ''));

            if ($event !== 'status_changed' && $event !== 'status_compare_and_swapped') {
                continue;
            }

            if ($to === 'released' && str_starts_with((string) ($metadata['release_reason'] ?? ''), 'client_reported_')) {
                $outcomes[] = $this->buildOutcome(
                    $taskPacketId,
                    'give_back',
                    (string) ($metadata['release_reason'] ?? ''),
                    (string) ($metadata['last_give_back_by'] ?? ''),
                    $recordedAt,
                );

                continue;
            }

            if ($to === 'completed_dry_run') {
                $outcomes[] = $this->buildOutcome(
                    $taskPacketId,
                    'completed',
                    (string) ($metadata['resolution'] ?? 'completed_dry_run'),
                    (string) ($metadata['agent_id'] ?? ''),
                    $recordedAt,
                );

                continue;
            }

            if ($to === 'cancelled') {
                $outcomes[] = $this->buildOutcome(
                    $taskPacketId,
                    'cancelled',
                    (string) ($metadata['reason'] ?? 'cancelled'),
                    (string) ($metadata['agent_id'] ?? ''),
                    $recordedAt,
                );
            }
        }

        return $outcomes;
    }

    /**
     * @return array{packet_id:string, packet_class:string, outcome:'completed'|'cancelled'|'give_back', reason:string, worker:string, recorded_at:string}|null
     */
    private function buildOutcome(
        string $taskPacketId,
        string $outcome,
        string $reason,
        string $worker,
        string $recordedAt,
    ): ?array {
        if ($taskPacketId === '' || $recordedAt === '') {
            return null;
        }

        if (! in_array($outcome, ['completed', 'cancelled', 'give_back'], true)) {
            return null;
        }

        return [
            'packet_id' => $taskPacketId,
            'packet_class' => $this->packetClass($taskPacketId),
            'outcome' => $outcome,
            'reason' => $reason,
            'worker' => $worker,
            'recorded_at' => $recordedAt,
        ];
    }

    private function packetClass(string $taskPacketId): string
    {
        if (preg_match('/^([A-Za-z0-9]+)(?:[-_]|$)/', $taskPacketId, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return strtolower($taskPacketId);
    }
}
