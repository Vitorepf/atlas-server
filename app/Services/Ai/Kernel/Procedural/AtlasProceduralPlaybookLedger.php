<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Throwable;

/**
 * ATLAS BUILD #3 SLICE 1 — store for the GENERAL procedural playbook.
 *
 * Reuses the SAME measurement substrate as the repair playbook ledger
 * ({@see \App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger}): on-disk
 * append-only JSONL that survives the DB, keyed by a stable task category, with
 * per-key resolution rate computed from REAL outcomes (never fabricated). The
 * delta is the SHAPE it stores — a task-agnostic {@see ProceduralPlaybook} with
 * the five canonical fields, not a domain-locked repair decision.
 *
 * Events (Slice 1 defines the first; applier/outcome/correction land in 2 & 3):
 *   {"event":"define","task_category":"...","objective":"...","steps":[...],
 *    "postconditions":[...],"forbidden_actions":[...],"prior_corrections":[...],
 *    "at":"ISO-8601"}
 */
final class AtlasProceduralPlaybookLedger
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (function_exists('storage_path')
            ? storage_path('atlas/kernel/procedural_playbook.jsonl')
            : sys_get_temp_dir().'/atlas/kernel/procedural_playbook.jsonl');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Producer: register (or re-register) a procedural playbook for a task category. */
    public function define(ProceduralPlaybook $playbook): void
    {
        if (trim($playbook->taskCategory) === '') {
            return;
        }
        $this->append(['event' => 'define'] + $playbook->toArray());
    }

    /**
     * Consumer: recover the latest playbook matching a task category (last
     * `define` wins, so re-definitions supersede). Returns null when nothing
     * matches — never a fabricated playbook.
     */
    public function retrieve(string $taskCategory): ?ProceduralPlaybook
    {
        $key = ProceduralPlaybook::normalizeCategory($taskCategory);
        if ($key === '') {
            return null;
        }

        $latest = null;
        foreach ($this->readAll() as $row) {
            if ((string) ($row['event'] ?? '') !== 'define') {
                continue;
            }
            if (ProceduralPlaybook::normalizeCategory((string) ($row['task_category'] ?? '')) !== $key) {
                continue;
            }
            $latest = $row;
        }

        return $latest !== null ? ProceduralPlaybook::fromArray($latest) : null;
    }

    /** @return list<array<string,mixed>> */
    private function readAll(): array
    {
        try {
            return AppendOnlyJsonlStore::read($this->path);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function append(array $payload): void
    {
        try {
            $payload['at'] = now()->toIso8601String();
            AppendOnlyJsonlStore::append($this->path, $payload);
        } catch (Throwable) {
            // fail-open: the corpus never blocks a work flow.
        }
    }
}
