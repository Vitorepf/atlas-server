<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ReentrySafety;

/**
 * Reentry-safety contract every phase calls before performing a side effect on resume.
 *
 * Consumes the checkpoint FACT from a checkpoint reader and a git-log probe (both duck-typed,
 * injected) and classifies each side-effect attempt as:
 *   - FRESH        — never attempted before; proceed.
 *   - ALREADY_DONE — prior attempt completed past the rename/commit boundary; return prior FACT.
 *   - TORN         — prior attempt started but did not cross the boundary; re-do safely.
 *
 * Side-effect kinds covered:
 *   - merge_to_main : checks checkpoint.merged_sha + git-log reachability.
 *   - emit_receipt  : checks checkpoint.emitted_receipt_ids set membership.
 *   - claim_task    : checks checkpoint.held_task_claim_ids set membership.
 *
 * NO PROXY: every check is on real persisted facts. Pure (read-only, deterministic).
 */
final class AtlasLoopCycleIdempotencyGuard
{
    public const FRESH = 'FRESH';
    public const ALREADY_DONE = 'ALREADY_DONE';
    public const TORN = 'TORN';

    public const KIND_MERGE_TO_MAIN = 'merge_to_main';
    public const KIND_EMIT_RECEIPT = 'emit_receipt';
    public const KIND_CLAIM_TASK = 'claim_task';

    /**
     * @param  object  $checkpointReader  duck-typed; must expose read(string $cycleId): ?array
     * @param  object  $gitLogProbe       duck-typed; must expose isReachableFromMain(string $sha): bool
     */
    public function __construct(
        private readonly object $checkpointReader,
        private readonly object $gitLogProbe,
    ) {}

    /**
     * @return array{verdict:string, kind:string, key:string, prior_fact:?array<string,mixed>}
     */
    public function classify(string $cycleId, string $sideEffectKind, string $key): array
    {
        $checkpoint = $this->checkpointReader->read($cycleId);
        if (! is_array($checkpoint)) {
            return $this->envelope(self::FRESH, $sideEffectKind, $key, null);
        }

        return match ($sideEffectKind) {
            self::KIND_MERGE_TO_MAIN => $this->classifyMerge($checkpoint, $key, $sideEffectKind),
            self::KIND_EMIT_RECEIPT => $this->classifySetMembership($checkpoint, 'emitted_receipt_ids', $key, $sideEffectKind),
            self::KIND_CLAIM_TASK => $this->classifySetMembership($checkpoint, 'held_task_claim_ids', $key, $sideEffectKind),
            default => $this->envelope(self::FRESH, $sideEffectKind, $key, null),
        };
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     * @return array{verdict:string, kind:string, key:string, prior_fact:?array<string,mixed>}
     */
    private function classifyMerge(array $checkpoint, string $baseCommitSha, string $kind): array
    {
        $mergedSha = $checkpoint['merged_sha'] ?? null;
        $checkpointBase = (string) ($checkpoint['base_commit_sha'] ?? '');

        if ($mergedSha === null) {
            return $this->envelope(self::FRESH, $kind, $baseCommitSha, null);
        }

        // If we have a merged_sha but the base it merged onto does not match the current attempt,
        // the prior attempt is for a different base — treat current attempt as FRESH.
        if ($checkpointBase !== '' && $checkpointBase !== $baseCommitSha) {
            return $this->envelope(self::FRESH, $kind, $baseCommitSha, null);
        }

        $reachable = (bool) $this->gitLogProbe->isReachableFromMain((string) $mergedSha);
        if ($reachable) {
            return $this->envelope(self::ALREADY_DONE, $kind, $baseCommitSha, [
                'merged_sha' => (string) $mergedSha,
                'base_commit_sha' => $checkpointBase,
            ]);
        }

        return $this->envelope(self::TORN, $kind, $baseCommitSha, [
            'merged_sha' => (string) $mergedSha,
            'base_commit_sha' => $checkpointBase,
        ]);
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     * @return array{verdict:string, kind:string, key:string, prior_fact:?array<string,mixed>}
     */
    private function classifySetMembership(array $checkpoint, string $setField, string $key, string $kind): array
    {
        $set = (array) ($checkpoint[$setField] ?? []);
        if (in_array($key, $set, true)) {
            return $this->envelope(self::ALREADY_DONE, $kind, $key, [$setField => array_values($set)]);
        }

        return $this->envelope(self::FRESH, $kind, $key, null);
    }

    /**
     * @return array{verdict:string, kind:string, key:string, prior_fact:?array<string,mixed>}
     */
    private function envelope(string $verdict, string $kind, string $key, ?array $priorFact): array
    {
        return [
            'verdict' => $verdict,
            'kind' => $kind,
            'key' => $key,
            'prior_fact' => $priorFact,
        ];
    }
}
