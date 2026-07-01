<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure knowledge-sync planner. Every closed Autonomous gap must declare the docs, memory,
 * code-index, and prompt-contract updates needed to prevent the same gap from being
 * reintroduced by a future brain or muscle session working from stale context.
 *
 * REQUIRED KNOWLEDGE-SYNC ACTIONS (all four, non-empty, to be sync_ready):
 *   docs_update | memory_update | code_index_update | prompt_contract_update
 *
 * OPTIONAL: `notes` — free-form context that never counts toward completeness; kept
 * separate from the required actions so a chatty gap record can never satisfy the gate
 * by padding notes instead of supplying real sync actions.
 *
 * A gap missing any required action is marked sync_incomplete with the specific missing
 * actions named. A complete gap is sync_ready=true and carries a stable (deterministic)
 * hash per required action, so downstream consumers can detect drift without re-reading
 * the raw update text.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainGapKnowledgeSyncPlan
{
    public const SCHEMA = 'atlas.external_brain.gap_knowledge_sync_plan.v1';

    public const REQUIRED_ACTIONS = ['docs_update', 'memory_update', 'code_index_update', 'prompt_contract_update'];

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @return array{schema:string, gaps:list<array<string,mixed>>, all_sync_ready:bool}
     */
    public function plan(array $gaps): array
    {
        $results = [];

        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $gapId = (string) ($gap['gap_id'] ?? '');
            $missing = [];
            $updateHashes = [];

            foreach (self::REQUIRED_ACTIONS as $action) {
                $value = $this->normalizedActionValue($gap[$action] ?? null);
                if ($value === '') {
                    $missing[] = $action;
                    $updateHashes[$action] = null;
                } else {
                    $updateHashes[$action] = hash('sha256', $action.'|'.$value);
                }
            }

            $notes = array_values(array_filter(array_map(
                'strval',
                (array) ($gap['notes'] ?? []),
            ), static fn (string $n): bool => $n !== ''));

            $results[] = [
                'gap_id'          => $gapId,
                'sync_ready'      => $missing === [],
                'missing_updates' => $missing,
                'update_hashes'   => $updateHashes,
                'notes'           => $notes,
            ];
        }

        $allSyncReady = $results !== [] && array_reduce(
            $results,
            static fn (bool $carry, array $r): bool => $carry && $r['sync_ready'],
            true,
        );

        return [
            'schema'         => self::SCHEMA,
            'gaps'           => $results,
            'all_sync_ready' => $allSyncReady,
        ];
    }

    /** Accepts a string or a list — both normalize to a stable, order-preserving string. */
    private function normalizedActionValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));

            return implode('|', $parts);
        }

        return trim((string) $value);
    }
}
