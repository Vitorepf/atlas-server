<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityFactSnapshotter;
use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityReshaper;
use Illuminate\Console\Command;

/**
 * Operator/agent-facing front door for the dynamic-priority subsystem.
 * Three verbs over snapshotter + reshaper; FACTS only; master-switch gated (OFF ⇒ byte-identical
 * disabled JSON, no writes).
 */
final class AtlasTaskMaestroPriorityCommand extends Command
{
    public const SCHEMA_SNAPSHOT = 'atlas.maestro.priority.snapshot.v1';

    public const SCHEMA_RESHAPE = 'atlas.maestro.priority.reshape.v1';

    public const SCHEMA_HISTORY = 'atlas.maestro.priority.history.v1';

    public const SCHEMA_DISABLED = 'atlas.maestro.priority.disabled.v1';

    public const SNAPSHOTS_PATH_BINDING = 'atlas.maestro.priority.snapshots_path';

    public const PENDING_PACKETS_SOURCE_BINDING = 'atlas.maestro.priority.pending_packets_source';

    public const QUEUE_TAG_BINDING = 'atlas.maestro.priority.queue_tag';

    private const WHITELISTED_STATUSES = ['snapshotted', 'reshaped', 'listed', 'disabled'];

    protected $signature = 'atlas:task:maestro:priority {action : snapshot|reshape|history} {--limit=20} {--client=} {--json}';

    protected $description = 'Operator surface for the maestro dynamic-priority loop (snapshot | reshape | history).';

    public function __construct(
        private readonly AtlasMaestroPriorityFactSnapshotter $snapshotter,
        private readonly AtlasMaestroPriorityReshaper $reshaper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->emitWhitelisted(['schema' => self::SCHEMA_DISABLED, 'status' => 'disabled', 'action' => $action]);
        }

        return match ($action) {
            'snapshot' => $this->doSnapshot(),
            'reshape' => $this->doReshape(),
            'history' => $this->doHistory(),
            default => $this->emit(['schema' => self::SCHEMA_DISABLED, 'status' => 'refused', 'reason' => 'unknown_action', 'action' => $action], 2),
        };
    }

    private function doSnapshot(): int
    {
        $row = $this->snapshotter->snapshot();
        $this->appendHistory(['kind' => 'snapshot', 'taken_at_ns' => (int) ($row['taken_at_ns'] ?? 0), 'row' => $row]);

        return $this->emitWhitelisted([
            'schema' => self::SCHEMA_SNAPSHOT,
            'status' => 'snapshotted',
            'snapshot' => $row,
        ]);
    }

    private function doReshape(): int
    {
        $snapshot = $this->latestSnapshot();
        $packets = $this->loadPendingPackets();
        $beforeHead = array_slice(array_column($packets, 'task_packet_id'), 0, 5);
        try {
            $ordered = $this->reshaper->reshape($packets, $snapshot, $this->queueTag());
        } catch (\Throwable $e) {
            return $this->emit([
                'schema' => self::SCHEMA_RESHAPE,
                'status' => 'refused',
                'reason' => $e->getMessage(),
            ], 1);
        }
        $afterHead = array_slice(array_column($ordered, 'task_packet_id'), 0, 5);
        $event = [
            'kind' => 'reshape',
            'taken_at_ns' => (int) ($snapshot['taken_at_ns'] ?? 0),
            'before_head' => $beforeHead,
            'after_head' => $afterHead,
        ];
        $this->appendHistory($event);

        return $this->emitWhitelisted([
            'schema' => self::SCHEMA_RESHAPE,
            'status' => 'reshaped',
            'before_head' => $beforeHead,
            'after_head' => $afterHead,
        ]);
    }

    private function doHistory(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = $this->readHistory();
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['taken_at_ns'] ?? 0)) <=> ((int) ($a['taken_at_ns'] ?? 0)));
        $rows = array_slice($rows, 0, $limit);

        return $this->emitWhitelisted([
            'schema' => self::SCHEMA_HISTORY,
            'status' => 'listed',
            'events' => $rows,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readHistory(): array
    {
        $path = $this->historyPath();
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function latestSnapshot(): array
    {
        $events = $this->readHistory();
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ((string) ($events[$i]['kind'] ?? '') === 'snapshot') {
                return (array) ($events[$i]['row'] ?? []);
            }
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadPendingPackets(): array
    {
        if (! $this->getLaravel()->bound(self::PENDING_PACKETS_SOURCE_BINDING)) {
            return [];
        }
        $source = $this->getLaravel()->make(self::PENDING_PACKETS_SOURCE_BINDING);
        if (! is_callable($source)) {
            return [];
        }
        $out = [];
        foreach ((array) $source() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function queueTag(): string
    {
        if ($this->getLaravel()->bound(self::QUEUE_TAG_BINDING)) {
            return (string) $this->getLaravel()->make(self::QUEUE_TAG_BINDING);
        }

        return 'loop';
    }

    private function historyPath(): string
    {
        if ($this->getLaravel()->bound(self::SNAPSHOTS_PATH_BINDING)) {
            return (string) $this->getLaravel()->make(self::SNAPSHOTS_PATH_BINDING);
        }

        return storage_path('app/atlas/maestro/dynamic-priority/history.jsonl');
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function appendHistory(array $event): void
    {
        $path = $this->historyPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $line = (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $sorted = $this->sortRecursive($payload);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($sorted, $flags));

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitWhitelisted(array $payload): int
    {
        $status = (string) ($payload['status'] ?? '');
        $exit = in_array($status, self::WHITELISTED_STATUSES, true) ? 0 : 1;

        return $this->emit($payload, $exit);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }
}
