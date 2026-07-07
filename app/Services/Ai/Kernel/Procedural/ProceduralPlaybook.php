<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

/**
 * ATLAS BUILD #3 — a GENERAL, task-agnostic procedural playbook (Devin-style),
 * the delta over {@see \App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger}
 * (which only measures repair RESOLUTION RATE, domain-locked to repair).
 *
 * Five canonical fields, provider-neutral, not domain-locked:
 *   - objective          — what "done" means for this class of task
 *   - steps              — the ordered procedure that worked before
 *   - postconditions     — what must hold after (proof the task actually landed)
 *   - forbiddenActions   — actions proven harmful for this class of task
 *   - priorCorrections   — derived from REAL failures (Slice 3), never fabricated
 *
 * Keyed by a stable task category so a new task inherits the procedure that
 * worked/failed before — procedural memory, not just static knowledge.
 */
final class ProceduralPlaybook
{
    /**
     * @param  list<string>  $steps
     * @param  list<string>  $postconditions
     * @param  list<string>  $forbiddenActions
     * @param  list<string>  $priorCorrections
     */
    public function __construct(
        public readonly string $taskCategory,
        public readonly string $objective,
        public readonly array $steps,
        public readonly array $postconditions,
        public readonly array $forbiddenActions,
        public readonly array $priorCorrections = [],
    ) {}

    /**
     * Stable match key — case/space-insensitive so retrieval by task category
     * is robust to caller formatting.
     */
    public static function normalizeCategory(string $taskCategory): string
    {
        return mb_strtolower(trim($taskCategory));
    }

    public function key(): string
    {
        return self::normalizeCategory($this->taskCategory);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            taskCategory: (string) ($row['task_category'] ?? ''),
            objective: (string) ($row['objective'] ?? ''),
            steps: self::stringList($row['steps'] ?? []),
            postconditions: self::stringList($row['postconditions'] ?? []),
            forbiddenActions: self::stringList($row['forbidden_actions'] ?? []),
            priorCorrections: self::stringList($row['prior_corrections'] ?? []),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'task_category' => $this->taskCategory,
            'objective' => $this->objective,
            'steps' => $this->steps,
            'postconditions' => $this->postconditions,
            'forbidden_actions' => $this->forbiddenActions,
            'prior_corrections' => $this->priorCorrections,
        ];
    }

    /**
     * A copy with extra prior-corrections appended (Slice 3 folds real-failure
     * corrections into retrieval). De-duplicated, order-preserving.
     *
     * @param  list<string>  $corrections
     */
    public function withPriorCorrections(array $corrections): self
    {
        $merged = array_values(array_unique(array_merge($this->priorCorrections, self::stringList($corrections))));

        return new self(
            taskCategory: $this->taskCategory,
            objective: $this->objective,
            steps: $this->steps,
            postconditions: $this->postconditions,
            forbiddenActions: $this->forbiddenActions,
            priorCorrections: $merged,
        );
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                is_array($value) ? $value : [],
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
