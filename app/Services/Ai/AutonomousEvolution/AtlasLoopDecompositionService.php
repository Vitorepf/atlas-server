<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;

/**
 * DECOMPOR (phase 5 of the canonical 8-phase live cycle) — split a CLEARED architecture draft (phase 4) into a
 * deterministic ordered list of atomic obra tasks the multi-agent IMPLEMENTAR phase consumes. It emits ONLY
 * the plan — it never executes, merges, or proposes.
 *
 * Two load-bearing wirings into existing substrate:
 *   - plan_fingerprint = {@see AtlasLoopDecompositionShapeFingerprinter}::fingerprint(plan)['hash'], so the
 *     decomposition-outcome corpus (the compounding seam) accumulates against the SAME fingerprints this live
 *     phase emits.
 *   - every emitted task's cited_symbols re-pass {@see AtlasLoopComprehensionGroundingGate} membership against
 *     the cleared draft's citation set — ATOMIC fail-closed: ANY ungrounded task citation ⇒ NO plan at all.
 *
 * Flag atlas.loop.decomposition_service_enabled default OFF ⇒ decompose() returns null (byte-identical no-op).
 */
final class AtlasLoopDecompositionService
{
    public const SCHEMA = 'atlas.loop.decomposition_plan.v1';

    public function __construct(
        private readonly ?AtlasLoopComprehensionGroundingGate $gate = null,
        private readonly ?AtlasLoopDecompositionShapeFingerprinter $fingerprinter = null,
    ) {}

    /**
     * @param  array<string,mixed>  $draftEnvelope  the phase-4 architecture draft envelope
     * @return array<string,mixed>|null
     */
    public function decompose(array $draftEnvelope): ?array
    {
        if (! (bool) config('atlas.loop.decomposition_service_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op
        }

        $objective = trim((string) ($draftEnvelope['objective'] ?? ''));
        $draftCited = array_values(array_filter((array) ($draftEnvelope['cited_symbols'] ?? []), 'is_string'));
        $files = array_values(array_filter((array) ($draftEnvelope['proposed_files'] ?? []), 'is_string'));
        sort($files, SORT_STRING);

        // The cleared draft's citations are the membership oracle: a decomposed task may not introduce a symbol
        // the architecture phase never grounded.
        $inventory = array_map(static fn (string $s): array => ['fqcn' => $s], $draftCited);
        $gate = $this->gate ?? new AtlasLoopComprehensionGroundingGate;

        // Pass 1 — ground EVERY task citation first; any refutation aborts the whole plan (atomicity).
        $refuted = [];
        foreach ($files as $file) {
            $citation = pathinfo($file, PATHINFO_FILENAME);
            $result = $gate->groundAgainstInventory($objective, [$citation], $inventory);
            foreach ((array) ($result['refuted'] ?? []) as $r) {
                $refuted[] = (string) $r;
            }
        }
        if ($refuted !== []) {
            return ['decomposed' => false, 'reason' => 'ungrounded_task_citation', 'refuted' => array_values(array_unique($refuted))];
        }

        // Pass 2 — build the deterministic ordered plan (one atomic task per proposed file, sequential waves).
        $orderedTasks = [];
        $previousId = null;
        foreach ($files as $index => $file) {
            $citedSymbols = [pathinfo($file, PATHINFO_FILENAME)];
            $allowedFiles = [$file];
            $taskObjective = $objective.' :: implement '.$file;
            $dependsOn = $previousId !== null ? [$previousId] : [];
            $taskId = substr(hash('sha256', $taskObjective.'|'.json_encode($citedSymbols, JSON_UNESCAPED_SLASHES).'|'.json_encode($allowedFiles, JSON_UNESCAPED_SLASHES)), 0, 24);

            $orderedTasks[] = [
                'task_id' => $taskId,
                'objective' => $taskObjective,
                'cited_symbols' => $citedSymbols,
                'allowed_files' => $allowedFiles,
                'depends_on' => $dependsOn,
                'wave' => $index,
            ];
            $previousId = $taskId;
        }

        // plan_fingerprint via the canonical fingerprinter (id/path-independent DAG-shape hash).
        $nodes = array_map(static fn (array $t): array => [
            'id' => $t['task_id'],
            'depends_on' => $t['depends_on'],
            'allowed_files' => $t['allowed_files'],
        ], $orderedTasks);
        $planFingerprint = (string) (($this->fingerprinter ?? new AtlasLoopDecompositionShapeFingerprinter)
            ->fingerprint(['nodes' => $nodes])['hash'] ?? '');

        return [
            'schema_version' => self::SCHEMA,
            'decomposed' => true,
            'plan_id' => substr(hash('sha256', $objective.'|'.json_encode($files, JSON_UNESCAPED_SLASHES)), 0, 16),
            'ordered_tasks' => $orderedTasks,
            'plan_fingerprint' => $planFingerprint,
        ];
    }
}
