<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Detects test-only poison packets produced after scope repair and emits a safe
 * recovery recommendation instead of leaving the packet claimable.
 *
 * A packet is "test-only poisoned" when scope repair has stripped every
 * implementation file while at least one test file remains — making it
 * impossible for any worker to satisfy the objective.
 *
 * Returns:
 *   claimable   — packet has impl + test files, no forbidden collision; scope preserved.
 *   unclaimable — test-only scope OR forbidden file collision detected; includes a
 *                 repair_action (readd_allowed_impl | split_operator_task | cancel_poison).
 *
 * Output includes repaired_allowed_files (the proposed new allowed_files after repair),
 * repair_confidence (how confident the repair is) and unsafe_reason when repair is not
 * worker-safe.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskFabricScopeRepairAdvisor
{
    public const SCHEMA = 'atlas.task_fabric.scope_repair_advisor.v1';

    public const STATUS_CLAIMABLE   = 'claimable';
    public const STATUS_UNCLAIMABLE = 'unclaimable';

    public const REASON_IMPLEMENTATION_SCOPE_REMOVED = 'implementation_scope_removed';
    public const REASON_FORBIDDEN_FILE_IN_SCOPE       = 'forbidden_file_in_allowed_scope';

    public const REPAIR_READD_IMPL        = 'readd_allowed_impl';
    public const REPAIR_SPLIT_OPERATOR    = 'split_operator_task';
    public const REPAIR_CANCEL_POISON     = 'cancel_poison';

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function advise(array $packet): array
    {
        $allowed   = $this->normalizeList($packet['allowed_files']   ?? []);
        $forbidden = $this->normalizeList($packet['forbidden_files'] ?? []);
        $objective = (string) ($packet['objective'] ?? '');

        $implFiles = array_values(array_filter($allowed, fn (string $f): bool => ! $this->isTestPath($f)));
        $testFiles = array_values(array_filter($allowed, fn (string $f): bool =>   $this->isTestPath($f)));

        // Test-only scope: no impl files remain but objective references an impl class.
        if ($implFiles === [] && $testFiles !== []) {
            $repairAction = $this->pickRepairAction($packet, $objective);
            $proposedImpl = $repairAction === self::REPAIR_READD_IMPL
                ? $this->guessImplFromObjective($objective)
                : null;
            $repaired = $proposedImpl !== null
                ? array_values(array_unique(array_merge($allowed, [$proposedImpl])))
                : $allowed;

            return [
                'schema'                 => self::SCHEMA,
                'status'                 => self::STATUS_UNCLAIMABLE,
                'reason'                 => self::REASON_IMPLEMENTATION_SCOPE_REMOVED,
                'repair_action'          => $repairAction,
                'scope_preserved'        => false,
                'impl_file_count'        => 0,
                'test_file_count'        => count($testFiles),
                'repaired_allowed_files' => $repaired,
                'repair_confidence'      => match ($repairAction) {
                    self::REPAIR_READD_IMPL     => 0.9,
                    self::REPAIR_SPLIT_OPERATOR => 0.5,
                    default                     => 0.1,
                },
                'unsafe_reason'           => $repairAction === self::REPAIR_CANCEL_POISON
                    ? 'No concrete impl target or operator context — packet cannot be repaired without human scoping'
                    : null,
            ];
        }

        // Forbidden file collision in allowed scope.
        if ($forbidden !== [] && array_intersect($allowed, $forbidden) !== []) {
            $repairAction = $this->pickRepairAction($packet, $objective);
            // Remove forbidden files from the repair proposal.
            $repaired = array_values(array_diff($allowed, $forbidden));

            return [
                'schema'                 => self::SCHEMA,
                'status'                 => self::STATUS_UNCLAIMABLE,
                'reason'                 => self::REASON_FORBIDDEN_FILE_IN_SCOPE,
                'repair_action'          => $repairAction,
                'scope_preserved'        => false,
                'impl_file_count'        => count($implFiles),
                'test_file_count'        => count($testFiles),
                'repaired_allowed_files' => $repaired,
                'repair_confidence'      => 0.8,
                'unsafe_reason'          => null,
            ];
        }

        // Happy path.
        return [
            'schema'                 => self::SCHEMA,
            'status'                 => self::STATUS_CLAIMABLE,
            'scope_preserved'        => true,
            'impl_file_count'        => count($implFiles),
            'test_file_count'        => count($testFiles),
            'repaired_allowed_files' => $allowed,
            'repair_confidence'      => 1.0,
            'unsafe_reason'          => null,
        ];
    }

    /**
     * Pick the most useful repair action given the packet context.
     *
     * Priority: if the objective names a resolvable impl target, re-add it.
     * If the packet requires operator involvement, split it as an operator task.
     * Otherwise the packet is irrecoverable — cancel it as a poison entry.
     */
    private function pickRepairAction(array $packet, string $objective): string
    {
        if ($this->objectiveNamesImplementationTarget($objective)) {
            return self::REPAIR_READD_IMPL;
        }
        if ((bool) ($packet['requires_operator'] ?? false)) {
            return self::REPAIR_SPLIT_OPERATOR;
        }

        return self::REPAIR_CANCEL_POISON;
    }

    /**
     * Heuristic: objective mentions "Implement", "Create", "Add" followed by an
     * Atlas-style class name (studly-case word), which implies an impl file is
     * recoverable from the original task intent.
     */
    private function objectiveNamesImplementationTarget(string $objective): bool
    {
        if ($objective === '') {
            return false;
        }
        // Matches: "Implement Atlas...", "Add Atlas...", "Create Atlas..."
        if (preg_match('/\b(?:Implement|Create|Add|Build|Extend|Write)\s+[A-Z][A-Za-z]+/', $objective)) {
            return true;
        }
        // Mentions a studly class name that suggests a concrete file target.
        return (bool) preg_match('/\bAtlas[A-Z][A-Za-z]+/', $objective);
    }

    /**
     * Derive a plausible impl file path from the objective text, using the
     * recognized class name.
     */
    private function guessImplFromObjective(string $objective): ?string
    {
        if (preg_match('/\b(?:Implement|Create|Add|Build|Extend|Write)\s+([A-Z][A-Za-z]+)/', $objective, $m)) {
            return 'app/Services/' . $m[1] . '.php';
        }
        if (preg_match('/\b(Atlas[A-Z][A-Za-z]+)/', $objective, $m)) {
            return 'app/Services/' . $m[1] . '.php';
        }

        return null;
    }

    /** @param  mixed  $raw */
    private function normalizeList($raw): array
    {
        return array_values(array_filter(array_map('strval', (array) $raw), static fn (string $s): bool => $s !== ''));
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_contains($path, '/tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }
}
