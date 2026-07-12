<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

/**
 * ASI-08 — Pattern-learning ledger writer.
 *
 * Append-only JSONL fed at the landing seam (server-side, not the worker claim).
 * Consumed by AtlasBrainCausalEffectGate + read-only path-yield surface.
 *
 * NO-SCALAR (mirrors AtlasBrainReflectionStream): stores raw facts only —
 * scope, task_id, action_hint, result_kind, proven_real, evidence refs. No stored score.
 */
final class AtlasBrainPatternLearningLedger
{
    public const SCHEMA = 'atlas.brain.pattern_learning_ledger.v1';

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_REJECTED = 'rejected';
    public const RESULT_BLOCKED = 'blocked';
    public const RESULT_NO_OP = 'clean_no_op';

    public const RESULT_KINDS = [
        self::RESULT_ACCEPTED,
        self::RESULT_REJECTED,
        self::RESULT_BLOCKED,
        self::RESULT_NO_OP,
    ];

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config(
            'atlas.brain.pattern_learning_ledger',
            storage_path('atlas-loop/pattern-learning-ledger.jsonl'),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string,mixed>  $entry {scope, task_id, action_hint, result_kind, proven_real?, evidence_refs?}
     * @return array<string,mixed>|null Written row, or null on fail-closed.
     */
    public function append(array $entry, ?int $at = null): ?array
    {
        $scope = trim((string) ($entry['scope'] ?? ''));
        $taskId = trim((string) ($entry['task_id'] ?? ''));
        $actionHint = trim((string) ($entry['action_hint'] ?? ''));
        $resultKind = (string) ($entry['result_kind'] ?? '');
        if ($scope === '' || $taskId === '' || $actionHint === '') {
            return null;
        }
        if (! in_array($resultKind, self::RESULT_KINDS, true)) {
            return null;
        }

        $row = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'task_id' => $taskId,
            'action_hint' => $actionHint,
            'result_kind' => $resultKind,
            'proven_real' => ($entry['proven_real'] ?? null) === true,
            'evidence_refs' => array_values(array_filter(
                (array) ($entry['evidence_refs'] ?? []),
                static fn ($ref): bool => is_string($ref) && trim($ref) !== '',
            )),
            'recorded_at' => $at ?? time(),
        ];

        try {
            (new JsonlReceiptStore($this->path))->append($row);
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        try {
            return (new JsonlReceiptStore($this->path))->replay();
        } catch (Throwable) {
            return [];
        }
    }
}
