<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Closure;
use Throwable;

/**
 * Reads cancelled / given-back task records from the live serving store and exposes them in the shape
 * {@see AtlasLoopCancelledTaskMiner}::mine() consumes ({task_packet_id, reason, allowed_files, cancelled_at,
 * optional gate/blocking_deficiencies/metadata}). The current-records source is injectable (closure / repository
 * object / explicit array) so tests pin controlled records without touching the live disk; the default reads the
 * serving queue via {@see AtlasTaskServingStack::queueRepo()} and is fail-open (a queue-read error yields []).
 */
final class AtlasLoopCancelledTaskServingReader
{
    public function __construct(private readonly object|array|null $source = null) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function recentRecords(int $limit): array
    {
        $limit = max(0, $limit);
        $records = array_values(array_filter($this->fromSource($limit), 'is_array'));
        if ($limit > 0 && count($records) > $limit) {
            $records = array_slice($records, 0, $limit);
        }

        return $records;
    }

    /**
     * @return list<mixed>
     */
    private function fromSource(int $limit): array
    {
        if (is_array($this->source)) {
            return array_values($this->source);
        }
        if ($this->source instanceof Closure) {
            $result = ($this->source)($limit);

            return is_array($result) ? array_values($result) : [];
        }
        if (is_object($this->source)) {
            foreach (['recentRecords', 'records', 'recent', 'read', 'list'] as $method) {
                if (method_exists($this->source, $method)) {
                    $result = $this->source->{$method}($limit);

                    return is_array($result) ? array_values($result) : [];
                }
            }

            return [];
        }

        return $this->fromServingStore();
    }

    /**
     * Best-effort projection of give-back / cancelled queue rows into the miner record shape. Fail-open.
     *
     * @return list<array<string,mixed>>
     */
    private function fromServingStore(): array
    {
        try {
            $records = [];
            foreach (['released', 'cancelled', 'given_back', 'blocked'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $packet = (array) (data_get($row, 'task_packet') ?? []);
                    $scope = (array) (data_get($packet, 'normalized_scope') ?? []);
                    $allowed = (array) ($scope['allowed_files'] ?? ($packet['allowed_files'] ?? []));
                    $records[] = [
                        'task_packet_id' => (string) (data_get($row, 'task_packet_id') ?? data_get($packet, 'task_packet_id') ?? ''),
                        'reason' => (string) (data_get($row, 'give_back.reason') ?? data_get($row, 'reason') ?? $status),
                        'allowed_files' => array_values(array_filter($allowed, 'is_string')),
                        'cancelled_at' => (string) (data_get($row, 'give_back.recorded_at') ?? data_get($row, 'released_at') ?? data_get($row, 'updated_at') ?? ''),
                        'blocking_deficiencies' => (array) (data_get($row, 'give_back.blocking_deficiencies') ?? []),
                    ];
                }
            }

            return $records;
        } catch (Throwable) {
            return [];
        }
    }
}
