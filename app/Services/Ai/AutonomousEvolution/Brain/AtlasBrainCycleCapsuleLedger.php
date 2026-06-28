<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Append-only store for {@see AtlasBrainCycleCapsule} records — one JSONL per scope slug under
 * config('atlas.brain.cycle_capsule_root'). The replayable history of external cycles the Internalization
 * Pipeline consumes. Mirrors {@see AtlasBrainDoneSetLedger}'s shape (per-scope JSONL, fail-open reads).
 */
final class AtlasBrainCycleCapsuleLedger
{
    private string $scopeSlug;

    private string $root;

    public function __construct(string $scopeSlug, ?string $root = null)
    {
        $this->scopeSlug = $this->slugify($scopeSlug);
        $this->root = rtrim($root ?? (string) config('atlas.brain.cycle_capsule_root'), '/');
    }

    /**
     * Capture + append one cycle as a replayable capsule. Returns the written capsule, or null when the cycle
     * is unattributable (no task_packet_id) — nothing is written in that case (fail-closed).
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>|null
     */
    public function record(array $cycle): ?array
    {
        $capsule = AtlasBrainCycleCapsule::capture($cycle);
        if ($capsule === null) {
            return null;
        }
        AppendOnlyJsonlStore::append($this->path(), ['recorded_at' => gmdate('c')] + $capsule);

        return $capsule;
    }

    /** @return list<array<string,mixed>> every capsule, in write order. */
    public function all(): array
    {
        return AppendOnlyJsonlStore::read($this->path());
    }

    /**
     * Replay lookup: every capsule recorded for a task_packet_id, in write order (latest last).
     *
     * @return list<array<string,mixed>>
     */
    public function forCycle(string $taskPacketId): array
    {
        $id = trim($taskPacketId);

        return array_values(array_filter(
            $this->all(),
            static fn (array $row): bool => (string) ($row['task_packet_id'] ?? '') === $id,
        ));
    }

    public function count(): int
    {
        return count($this->all());
    }

    private function path(): string
    {
        return $this->root.'/'.$this->scopeSlug.'.jsonl';
    }

    private function slugify(string $slug): string
    {
        $clean = strtolower(trim($slug));
        $clean = (string) preg_replace('/[^a-z0-9]+/', '-', $clean);

        return trim($clean, '-') ?: 'default';
    }
}
