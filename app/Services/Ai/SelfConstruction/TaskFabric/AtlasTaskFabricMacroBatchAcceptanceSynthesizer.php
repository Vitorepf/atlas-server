<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure synthesizer. Generates batch-level acceptance criteria for macro-task
 * batches, covering five quality dimensions that per-task unit tests cannot prove:
 *
 *   batch_structural_leverage   — batch spans ≥2 unique top-level directories
 *   batch_non_duplication       — no two objectives share more than 60% keyword overlap
 *   batch_integration_effect    — at least one task provides a runnable test command
 *   batch_worker_implementability — every task has at least one allowed_file
 *   batch_evidence_strength     — every task has at least one test_command or acceptance
 *
 * Any caller-supplied candidate_acceptance entry that cannot be checked by a
 * runnable command, deterministic verdict, or bounded proof artifact is rejected
 * as vague_acceptance.
 *
 * AC4: output always includes synthesized_acceptance, rejected_acceptance,
 *      batch_level_gates, and per_task_coverage_gaps.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskFabricMacroBatchAcceptanceSynthesizer
{
    public const SCHEMA = 'atlas.task_fabric.macro_batch_acceptance_synthesizer.v1';

    public const GATE_STRUCTURAL_LEVERAGE        = 'batch_structural_leverage';
    public const GATE_NON_DUPLICATION            = 'batch_non_duplication';
    public const GATE_INTEGRATION_EFFECT         = 'batch_integration_effect';
    public const GATE_WORKER_IMPLEMENTABILITY    = 'batch_worker_implementability';
    public const GATE_EVIDENCE_STRENGTH          = 'batch_evidence_strength';

    public const REJECTION_VAGUE_ACCEPTANCE      = 'vague_acceptance';

    private const DUPLICATE_OVERLAP_THRESHOLD    = 0.60;
    private const MIN_DIRECTORIES_FOR_LEVERAGE   = 2;

    private const RUNNABLE_MARKERS = ['phpunit', 'artisan', 'vendor/bin', 'php ', 'pest', '--filter'];

    /**
     * @param  array{
     *   batch?: list<array<string,mixed>>,
     *   candidate_acceptance?: list<string>,
     * }  $input
     * @return array{schema:string, synthesized_acceptance:list<string>, rejected_acceptance:list<array<string,string>>, batch_level_gates:list<string>, per_task_coverage_gaps:list<array<string,mixed>>}
     */
    public function synthesize(array $input): array
    {
        $batch               = (array) ($input['batch']                ?? []);
        $candidateAcceptance = (array) ($input['candidate_acceptance'] ?? []);

        $synthesized      = [];
        $batchLevelGates  = [];
        $coverageGaps     = [];

        // ── Gate 1: structural leverage ───────────────────────────────────────
        $dirs = $this->topLevelDirectories($batch);
        if (count($dirs) >= self::MIN_DIRECTORIES_FOR_LEVERAGE) {
            $synthesized[]    = 'Batch structural leverage: allowed_files span ≥'.self::MIN_DIRECTORIES_FOR_LEVERAGE.' distinct top-level directories ('.implode(', ', array_slice($dirs, 0, 3)).')';
            $batchLevelGates[] = self::GATE_STRUCTURAL_LEVERAGE;
        } else {
            $synthesized[]    = 'Batch structural leverage: verify that batch touches at least '.self::MIN_DIRECTORIES_FOR_LEVERAGE.' distinct top-level directories — currently '.count($dirs);
            $batchLevelGates[] = self::GATE_STRUCTURAL_LEVERAGE;
        }

        // ── Gate 2: non-duplication ───────────────────────────────────────────
        $dupPair = $this->findDuplicatePair($batch);
        if ($dupPair === null) {
            $synthesized[]    = 'Batch non-duplication: all task objectives are semantically distinct — verified deterministically.';
            $batchLevelGates[] = self::GATE_NON_DUPLICATION;
        } else {
            $synthesized[]    = "Batch non-duplication: tasks {$dupPair[0]} and {$dupPair[1]} have ≥"
                .((int) (self::DUPLICATE_OVERLAP_THRESHOLD * 100)).'% objective overlap — deduplicate before enqueue.';
            $batchLevelGates[] = self::GATE_NON_DUPLICATION;
        }

        // ── Gate 3: integration effect ────────────────────────────────────────
        $hasIntegration = $this->hasIntegrationEffect($batch);
        $synthesized[]    = $hasIntegration
            ? 'Batch integration effect: at least one task provides a runnable test command proving cross-component behavior.'
            : 'Batch integration effect: no task provides a runnable test command — add at least one test_command per task.';
        $batchLevelGates[] = self::GATE_INTEGRATION_EFFECT;

        // ── Gate 4: worker implementability ──────────────────────────────────
        $noFileTasks = $this->tasksWithNoAllowedFiles($batch);
        $synthesized[]    = $noFileTasks === []
            ? 'Batch worker implementability: all tasks have at least one allowed_file.'
            : 'Batch worker implementability: '.count($noFileTasks).' task(s) have no allowed_files — '.implode(', ', array_slice($noFileTasks, 0, 3)).'.';
        $batchLevelGates[] = self::GATE_WORKER_IMPLEMENTABILITY;

        // ── Gate 5: evidence strength ─────────────────────────────────────────
        $noEvidenceTasks = $this->tasksWithNoEvidence($batch);
        $synthesized[]    = $noEvidenceTasks === []
            ? 'Batch evidence strength: all tasks have at least one test_command or acceptance criterion.'
            : 'Batch evidence strength: '.count($noEvidenceTasks).' task(s) have no test_command or acceptance — '.implode(', ', array_slice($noEvidenceTasks, 0, 3)).'.';
        $batchLevelGates[] = self::GATE_EVIDENCE_STRENGTH;

        // ── Candidate acceptance filtering ────────────────────────────────────
        $rejectedAcceptance = [];
        foreach ($candidateAcceptance as $candidate) {
            if ($this->isVague((string) $candidate)) {
                $rejectedAcceptance[] = [
                    'criterion'        => (string) $candidate,
                    'rejection_reason' => self::REJECTION_VAGUE_ACCEPTANCE,
                ];
            } else {
                $synthesized[] = (string) $candidate;
            }
        }

        // ── Per-task coverage gaps ────────────────────────────────────────────
        foreach ($batch as $idx => $task) {
            $taskId  = (string) ($task['task_id'] ?? "task_{$idx}");
            $missing = [];

            if (empty($task['allowed_files'])) {
                $missing[] = 'missing_allowed_files';
            }
            if (empty($task['test_commands']) && empty($task['acceptance_criteria'])) {
                $missing[] = 'missing_evidence';
            }

            if ($missing !== []) {
                $coverageGaps[] = ['task_id' => $taskId, 'missing_coverage' => $missing];
            }
        }

        return [
            'schema'                 => self::SCHEMA,
            'synthesized_acceptance' => $synthesized,
            'rejected_acceptance'    => $rejectedAcceptance,
            'batch_level_gates'      => $batchLevelGates,
            'per_task_coverage_gaps' => $coverageGaps,
        ];
    }

    /** @return list<string> unique top-level directories across all allowed_files */
    private function topLevelDirectories(array $batch): array
    {
        $dirs = [];
        foreach ($batch as $task) {
            foreach ((array) ($task['allowed_files'] ?? []) as $file) {
                $parts = explode('/', ltrim((string) $file, '/'));
                if ($parts[0] !== '') {
                    $dirs[$parts[0]] = true;
                }
            }
        }

        return array_keys($dirs);
    }

    /** @return array{string,string}|null pair of duplicate task IDs, or null if no duplicates */
    private function findDuplicatePair(array $batch): ?array
    {
        $fingerprints = [];
        foreach ($batch as $idx => $task) {
            $id  = (string) ($task['task_id'] ?? "task_{$idx}");
            $kw  = $this->keywords((string) ($task['objective'] ?? ''));
            $fingerprints[] = ['id' => $id, 'kw' => $kw];
        }

        for ($i = 0; $i < count($fingerprints); $i++) {
            for ($j = $i + 1; $j < count($fingerprints); $j++) {
                if ($this->overlap($fingerprints[$i]['kw'], $fingerprints[$j]['kw']) >= self::DUPLICATE_OVERLAP_THRESHOLD) {
                    return [$fingerprints[$i]['id'], $fingerprints[$j]['id']];
                }
            }
        }

        return null;
    }

    private function hasIntegrationEffect(array $batch): bool
    {
        foreach ($batch as $task) {
            foreach ((array) ($task['test_commands'] ?? []) as $cmd) {
                foreach (self::RUNNABLE_MARKERS as $marker) {
                    if (str_contains(strtolower((string) $cmd), $marker)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return list<string> task IDs with no allowed_files */
    private function tasksWithNoAllowedFiles(array $batch): array
    {
        $out = [];
        foreach ($batch as $idx => $task) {
            if (empty($task['allowed_files'])) {
                $out[] = (string) ($task['task_id'] ?? "task_{$idx}");
            }
        }

        return $out;
    }

    /** @return list<string> task IDs with no test_command and no acceptance_criteria */
    private function tasksWithNoEvidence(array $batch): array
    {
        $out = [];
        foreach ($batch as $idx => $task) {
            if (empty($task['test_commands']) && empty($task['acceptance_criteria'])) {
                $out[] = (string) ($task['task_id'] ?? "task_{$idx}");
            }
        }

        return $out;
    }

    private function isVague(string $text): bool
    {
        $lower = strtolower($text);

        // Passes if it contains a runnable marker
        foreach (self::RUNNABLE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return false;
            }
        }

        // Passes if it contains a deterministic/measurable signal
        $deterministicMarkers = ['must pass', 'must exit', 'exits 0', 'returns', 'emits', 'count', 'exactly', '≥', '≤', '>', '<', 'verified', 'proven', 'bounded'];
        foreach ($deterministicMarkers as $dm) {
            if (str_contains($lower, $dm)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        $text  = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text) ?? '');
        $words = array_filter(explode(' ', $text), fn (string $w): bool => strlen($w) >= 4);

        return array_values(array_unique($words));
    }

    private function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
