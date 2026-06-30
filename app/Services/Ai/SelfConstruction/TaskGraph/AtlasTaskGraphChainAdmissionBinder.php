<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricBrutalValueAdmissionGate;

/**
 * Pure binder: combines strategic task chains with the brutal value admission gate
 * so only chains with valid dependency ordering, non-overlapping write-sets and
 * individually gate-passing steps enter a batch.
 *
 * Validation applied to each chain (all failures collected; any → rejected):
 *   gate_rejection           — one or more steps fail the brutal value admission gate
 *   depends_on_order_violation — a step's depends_on references a task that appears at
 *                               an equal or later position in the steps array
 *   write_set_conflict       — two parallel steps (no direct depends_on between them)
 *                               share at least one allowed_file path
 *
 * OUTPUT:
 *   accepted_chains[]   — chains that passed all checks; includes step_count
 *   rejected_chains[]   — chains that failed; includes rejection_reasons[]
 *   depends_on_edges[]  — {from, to, chain_id} for every depends_on in all chains
 *   write_set_conflicts[]— {chain_id, task_a, task_b, conflicting_files[]} per conflict
 *
 * PURE / DETERMINISTIC. Never enqueues, writes, calls providers or mutates state.
 */
final class AtlasTaskGraphChainAdmissionBinder
{
    public const SCHEMA = 'atlas.task_graph.chain_admission_binder.v1';

    public function __construct(
        private readonly AtlasTaskFabricBrutalValueAdmissionGate $gate = new AtlasTaskFabricBrutalValueAdmissionGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input  chains + optional shared_facts
     * @return array<string,mixed>
     */
    public function bind(array $input): array
    {
        $chains = is_array($input['chains'] ?? null) ? $input['chains'] : [];
        $sharedFacts = is_array($input['shared_facts'] ?? null) ? $input['shared_facts'] : [];

        $accepted = [];
        $rejected = [];
        $edges = [];
        $conflicts = [];

        foreach ($chains as $chain) {
            $chainId = (string) ($chain['chain_id'] ?? 'unknown');
            $steps = is_array($chain['steps'] ?? null) ? $chain['steps'] : [];
            $chainReasons = [];

            // Build step position index for ordering checks.
            $positionByTask = [];
            foreach ($steps as $pos => $step) {
                $positionByTask[(string) ($step['task_id'] ?? '')] = $pos;
            }

            // 1. Gate admission for each step.
            foreach ($steps as $step) {
                $verdict = $this->gate->decide(array_merge($step, $sharedFacts));
                if (! ($verdict['admitted'] ?? false)) {
                    $chainReasons[] = 'gate_rejection';
                    break;
                }
            }

            // 2. Depends_on ordering: dep must appear before dependent.
            foreach ($steps as $pos => $step) {
                $deps = is_array($step['depends_on'] ?? null) ? $step['depends_on'] : [];
                $taskId = (string) ($step['task_id'] ?? '');

                foreach ($deps as $dep) {
                    $dep = (string) $dep;
                    $depPos = $positionByTask[$dep] ?? null;

                    if ($depPos === null || $depPos >= $pos) {
                        $chainReasons[] = 'depends_on_order_violation';
                    }

                    // Collect edge regardless (for output).
                    $edges[] = ['from' => $dep, 'to' => $taskId, 'chain_id' => $chainId];
                }
            }

            // 3. Write-set conflicts among parallel steps.
            $chainConflicts = $this->writeSetConflicts($chainId, $steps);
            if (count($chainConflicts) > 0) {
                $chainReasons[] = 'write_set_conflict';
                foreach ($chainConflicts as $c) {
                    $conflicts[] = $c;
                }
            }

            $chainReasons = array_values(array_unique($chainReasons));
            $entry = ['chain_id' => $chainId, 'step_count' => count($steps), 'rejection_reasons' => $chainReasons];

            if ($chainReasons === []) {
                $accepted[] = $entry;
            } else {
                $rejected[] = $entry;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'accepted_chains' => $accepted,
            'rejected_chains' => $rejected,
            'depends_on_edges' => $edges,
            'write_set_conflicts' => $conflicts,
        ];
    }

    /** @return array<array<string,mixed>> */
    private function writeSetConflicts(string $chainId, array $steps): array
    {
        $conflicts = [];
        $n = count($steps);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $steps[$i];
                $b = $steps[$j];
                $aId = (string) ($a['task_id'] ?? '');
                $bId = (string) ($b['task_id'] ?? '');

                $aDeps = is_array($a['depends_on'] ?? null) ? $a['depends_on'] : [];
                $bDeps = is_array($b['depends_on'] ?? null) ? $b['depends_on'] : [];

                $directDep = in_array($bId, $aDeps, true) || in_array($aId, $bDeps, true);

                if (! $directDep) {
                    $aFiles = is_array($a['allowed_files'] ?? null) ? $a['allowed_files'] : [];
                    $bFiles = is_array($b['allowed_files'] ?? null) ? $b['allowed_files'] : [];
                    $overlap = array_values(array_intersect($aFiles, $bFiles));

                    if (count($overlap) > 0) {
                        $conflicts[] = [
                            'chain_id' => $chainId,
                            'task_a' => $aId,
                            'task_b' => $bId,
                            'conflicting_files' => $overlap,
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }
}
