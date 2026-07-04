<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure orchestrator: walks pre-computed organ results through a deterministic
 * phase pipeline and emits one consolidated next-batch decision.
 *
 * Phase order: context → proposal → critique → value → readiness → queue_decision
 *
 * Fail-closed rules — a phase BLOCKS when:
 *   - missing from organ_results
 *   - its result has stale=true
 *   - its result has contradictory=true
 *
 * On block: sets blocking_phase, final_decision='blocked', and stops.
 * On full pass: final_decision='proceed'.
 *
 * next_batch_constraints are aggregated from each passing phase that carries
 * a 'constraints' list.
 */
final class AtlasExternalBrainOrganMeshOrchestrator
{
    public const SCHEMA = 'atlas.external_brain.organ_mesh_orchestrator.v1';

    public const PHASES = ['context', 'proposal', 'critique', 'value', 'readiness', 'queue_decision'];

    public const MESH_STATUS_VALID = 'valid';
    public const MESH_STATUS_INVALID = 'invalid';

    /**
     * Builds an organ mesh from typed organs (input/output contracts) and
     * edges (data or feedback), instead of treating organs as isolated
     * callable classes. Flags missing contract matches, data-edge cycles
     * (which would never terminate), and orphan organs with no wiring at all.
     *
     * @param  array{organs?: list<array<string,mixed>>, edges?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function buildMesh(array $input): array
    {
        $organs = array_values((array) ($input['organs'] ?? []));
        $edges = array_values((array) ($input['edges'] ?? []));

        $organsById = [];
        foreach ($organs as $organ) {
            $organ = (array) $organ;
            $organId = (string) ($organ['organ_id'] ?? '');
            if ($organId === '') {
                continue;
            }
            $organsById[$organId] = [
                'input_contracts' => array_values(array_map('strval', (array) ($organ['input_contracts'] ?? []))),
                'output_contracts' => array_values(array_map('strval', (array) ($organ['output_contracts'] ?? []))),
            ];
        }

        $invalidEdges = [];
        $dataAdjacency = [];
        $feedbackEdges = [];
        $connected = [];

        foreach ($edges as $edge) {
            $edge = (array) $edge;
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $type = (string) ($edge['type'] ?? 'data');

            if (! isset($organsById[$from]) || ! isset($organsById[$to])) {
                $invalidEdges[] = ['from' => $from, 'to' => $to, 'reason' => 'unknown_organ_in_edge'];
                continue;
            }

            $sharesContract = array_intersect($organsById[$from]['output_contracts'], $organsById[$to]['input_contracts']) !== [];
            if (! $sharesContract) {
                $invalidEdges[] = ['from' => $from, 'to' => $to, 'reason' => 'missing_contract_match'];
                continue;
            }

            $connected[$from] = true;
            $connected[$to] = true;

            if ($type === 'feedback') {
                $feedbackEdges[] = ['from' => $from, 'to' => $to];

                continue;
            }

            $dataAdjacency[$from][] = $to;
        }

        [$executionOrder, $cycleDetected] = $this->topologicalSort(array_keys($organsById), $dataAdjacency);

        // Cycle edges: data edges between nodes that were not processed (part of a cycle)
        $cycleNodes = $cycleDetected ? array_diff(array_keys($organsById), $executionOrder) : [];
        $cycleEdges = [];
        if ($cycleDetected) {
            foreach ($dataAdjacency as $from => $targets) {
                foreach ($targets as $to) {
                    if (in_array($from, $cycleNodes, true) && in_array($to, $cycleNodes, true)) {
                        $cycleEdges[] = ['from' => $from, 'to' => $to];
                    }
                }
            }
        }

        $orphanOrgans = array_values(array_filter(
            array_keys($organsById),
            static fn (string $organId): bool => ! isset($connected[$organId]),
        ));

        $meshStatus = ($invalidEdges === [] && ! $cycleDetected) ? self::MESH_STATUS_VALID : self::MESH_STATUS_INVALID;

        $nextIntegrationTask = match (true) {
            $invalidEdges !== [] => "fix edge {$invalidEdges[0]['from']}->{$invalidEdges[0]['to']}: {$invalidEdges[0]['reason']}",
            $cycleDetected => 'break the data-edge cycle so the mesh terminates',
            $orphanOrgans !== [] => "wire orphan organ '{$orphanOrgans[0]}' into the mesh",
            default => null,
        };

        return [
            'schema_version' => self::SCHEMA,
            'organs' => $organsById,
            'execution_order' => $executionOrder,
            'cycle_detected' => $cycleDetected,
            'cycle_edges' => $cycleEdges,
            'feedback_edges' => $feedbackEdges,
            'orphan_organs' => $orphanOrgans,
            'mesh_status' => $meshStatus,
            'invalid_edges' => $invalidEdges,
            'next_integration_task' => $nextIntegrationTask,
        ];
    }

    /**
     * Kahn's algorithm. Returns [execution_order, cycle_detected].
     *
     * @param  list<string>  $organIds
     * @param  array<string, list<string>>  $adjacency
     * @return array{0: list<string>, 1: bool}
     */
    private function topologicalSort(array $organIds, array $adjacency): array
    {
        $inDegree = array_fill_keys($organIds, 0);
        foreach ($adjacency as $from => $targets) {
            foreach ($targets as $to) {
                $inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
            }
        }

        $queue = array_values(array_filter($organIds, static fn (string $id): bool => $inDegree[$id] === 0));
        $order = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $current;
            foreach ($adjacency[$current] ?? [] as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        $cycleDetected = count($order) !== count($organIds);

        return [$order, $cycleDetected];
    }

    /**
     * @param  array<string,mixed>  $input  organ_results keyed by phase name
     * @return array<string,mixed>
     */
    public function orchestrate(array $input): array
    {
        $organResults = is_array($input['organ_results'] ?? null) ? $input['organ_results'] : [];

        $phaseOutputs = [];
        $blockingPhase = null;
        $nextBatchConstraints = [];
        $compactTrace = [];

        foreach (self::PHASES as $phase) {
            if (! array_key_exists($phase, $organResults)) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'missing'];
                break;
            }

            $result = $organResults[$phase];

            if (! empty($result['stale'])) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'stale'];
                break;
            }

            if (! empty($result['contradictory'])) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'contradictory'];
                break;
            }

            $phaseOutputs[$phase] = $result;
            $compactTrace[] = ['phase' => $phase, 'status' => 'passed'];

            if (is_array($result['constraints'] ?? null)) {
                foreach ($result['constraints'] as $constraint) {
                    $nextBatchConstraints[] = $constraint;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'phase_outputs' => $phaseOutputs,
            'blocking_phase' => $blockingPhase,
            'final_decision' => $blockingPhase === null ? 'proceed' : 'blocked',
            'next_batch_constraints' => $nextBatchConstraints,
            'compact_trace' => $compactTrace,
        ];
    }
}
