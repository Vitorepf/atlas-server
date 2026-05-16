<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\RunIndex;

use App\Models\AtlasDevRunIndex;
use InvalidArgumentException;

/**
 * Lightweight index repository for Atlas Dev runs.
 *
 * This is NOT the source of truth for run artifacts — that role belongs to
 * `ReceiptStorage` (filesystem) and the Atlas Dev schema DTOs. This table
 * only mirrors enough routing/completion state for Desktop/CLI list/resume.
 *
 * Update flow:
 *   - upsertFromPlan(...) is called once after the plan-only pipeline finishes
 *     so the run becomes visible (routing/task/risk locked).
 *   - updateCompletion(...) is called by the gate/escalation layer to flip
 *     completion_state and pin the last receipt hash.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
 */
class AtlasDevRunIndexRepository
{
    public function upsertFromPlan(
        string $runId,
        string $surfaceId,
        string $workspaceHash,
        string $routingDecision,
        string $taskKind,
        string $riskLevel,
        ?string $threadId = null,
    ): AtlasDevRunIndexEntry {
        $this->assertNonEmpty('run_id', $runId);
        $this->assertNonEmpty('surface_id', $surfaceId);
        $this->assertNonEmpty('workspace_hash', $workspaceHash);
        $this->assertNonEmpty('routing_decision', $routingDecision);
        $this->assertNonEmpty('task_kind', $taskKind);
        $this->assertNonEmpty('risk_level', $riskLevel);

        $row = AtlasDevRunIndex::query()->find($runId);

        if ($row === null) {
            $row = new AtlasDevRunIndex;
            $row->run_id = $runId;
        }

        $row->surface_id = $surfaceId;
        $row->workspace_hash = $workspaceHash;
        $row->thread_id = $threadId;
        $row->routing_decision = $routingDecision;
        $row->task_kind = $taskKind;
        $row->risk_level = $riskLevel;
        $row->save();

        return AtlasDevRunIndexEntry::fromModel($row->refresh());
    }

    public function updateCompletion(
        string $runId,
        string $completionState,
        ?string $lastReceiptHash = null,
    ): ?AtlasDevRunIndexEntry {
        $this->assertNonEmpty('run_id', $runId);
        $this->assertNonEmpty('completion_state', $completionState);

        $row = AtlasDevRunIndex::query()->find($runId);
        if ($row === null) {
            return null;
        }

        $row->completion_state = $completionState;
        if ($lastReceiptHash !== null) {
            $row->last_receipt_hash = $lastReceiptHash;
        }
        $row->save();

        return AtlasDevRunIndexEntry::fromModel($row->refresh());
    }

    public function find(string $runId): ?AtlasDevRunIndexEntry
    {
        if ($runId === '') {
            return null;
        }

        $row = AtlasDevRunIndex::query()->find($runId);

        return $row === null ? null : AtlasDevRunIndexEntry::fromModel($row);
    }

    /**
     * @return list<AtlasDevRunIndexEntry>
     */
    public function listByThread(string $threadId, ?int $limit = null): array
    {
        if ($threadId === '') {
            return [];
        }

        return $this->collectOrdered(
            AtlasDevRunIndex::query()->where('thread_id', $threadId),
            $limit,
        );
    }

    /**
     * @return list<AtlasDevRunIndexEntry>
     */
    public function listByWorkspace(string $workspaceHash, ?int $limit = null): array
    {
        if ($workspaceHash === '') {
            return [];
        }

        return $this->collectOrdered(
            AtlasDevRunIndex::query()->where('workspace_hash', $workspaceHash),
            $limit,
        );
    }

    /**
     * @return list<AtlasDevRunIndexEntry>
     */
    private function collectOrdered($query, ?int $limit): array
    {
        $resolved = $this->resolveLimit($limit);

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('run_id')
            ->limit($resolved)
            ->get();

        return array_values(array_map(
            static fn (AtlasDevRunIndex $row): AtlasDevRunIndexEntry => AtlasDevRunIndexEntry::fromModel($row),
            $rows->all(),
        ));
    }

    private function resolveLimit(?int $limit): int
    {
        $default = max(1, (int) config('atlas_dev.run_index.list_default_limit', 50));
        $max = max(1, (int) config('atlas_dev.run_index.list_max_limit', 200));
        $effective = max(1, $limit ?? $default);

        return min($effective, $max);
    }

    private function assertNonEmpty(string $field, string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("AtlasDevRunIndexRepository.{$field} must be non-empty.");
        }
    }
}
