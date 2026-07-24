<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Support\UtcIsoTimestamp;

/**
 * Per-scope done-set ledger for the external brain — the append-only memory of which targets the brain has
 * already originated work for. One JSONL file per scope slug under config('atlas.brain.done_set_root'), so a
 * scope's history is isolated. Thin wrapper over {@see AppendOnlyJsonlStore}: append a cycle row, read the last
 * N, and answer isDone() (dedup keyed on target_path — STICKY: once a target appears, it stays done).
 *
 * The dedup keeps the brain from re-originating the same target every tick. recentCycles() feeds the dry-probe.
 */
final class AtlasBrainDoneSetLedger
{
    public const CYCLE_SCHEMA = 'atlas.brain.cycle.v1';

    private readonly string $root;

    private readonly string $scopeSlug;

    public function __construct(
        string $scopeSlug,
        ?string $root = null,
    ) {
        $this->scopeSlug = $this->slugify($scopeSlug);
        $this->root = rtrim($root ?? (string) config('atlas.brain.done_set_root'), '/');
    }

    /**
     * Append one cycle row. Fills the schema + recorded_at; the caller supplies the rest.
     *
     * @param  array<string,mixed>  $cycleRow
     */
    public function record(array $cycleRow): void
    {
        AppendOnlyJsonlStore::append($this->path(), [
            'schema' => self::CYCLE_SCHEMA,
            'recorded_at' => UtcIsoTimestamp::now(),
            'snapshot_id' => (string) ($cycleRow['snapshot_id'] ?? ''),
            'status' => (string) ($cycleRow['status'] ?? ''),
            'produced' => (bool) ($cycleRow['produced'] ?? false),
            'action' => (string) ($cycleRow['action'] ?? ''),
            'target_path' => (string) ($cycleRow['target_path'] ?? ''),
            'task_packet_id' => (string) ($cycleRow['task_packet_id'] ?? ''),
            'refusal' => (bool) ($cycleRow['refusal'] ?? false),
        ]);
    }

    /**
     * The last $n cycle rows (chronological — oldest of the window first), so the dry-probe can read the tail.
     *
     * @return list<array<string,mixed>>
     */
    public function recentCycles(int $n): array
    {
        $rows = AppendOnlyJsonlStore::read($this->path());

        return $n <= 0 ? [] : array_values(array_slice($rows, -$n));
    }

    /**
     * Statuses that burn a target for sticky dedup.
     *
     * `served` is intentionally excluded: brain `next` records a served proposal
     * before `seed` enqueues it. Burning on served made next→seed return
     * skipped_done_set and never enqueue (Elite Factory v2 P0-16).
     *
     * @var list<string>
     */
    public const BURNING_STATUSES = [
        'seeded',
        'already_done',
        'forbidden_target',
        'completed',
        'resolved',
    ];

    /**
     * Has a packet already been committed for this target?
     * STICKY dedup on a non-empty target_path, but only for burning statuses.
     */
    public function isDone(string $targetPath): bool
    {
        $target = trim($targetPath);
        if ($target === '') {
            return false;
        }

        foreach (AppendOnlyJsonlStore::read($this->path()) as $row) {
            if (trim((string) ($row['target_path'] ?? '')) !== $target) {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, self::BURNING_STATUSES, true)) {
                return true;
            }
        }

        return false;
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
