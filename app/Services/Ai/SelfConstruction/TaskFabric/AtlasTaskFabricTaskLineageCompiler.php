<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Compiles task specs into an explicit lineage graph: prerequisites, unlocks, evidence dependencies,
 * and terminal stop conditions. Ensures the external brain creates coherent chains rather than
 * isolated packets.
 *
 * INPUT: list of task specs, each carrying:
 *   { id:string, capabilities:list<string>, dependencies:list<string>, unlocks:list<string>,
 *     exploratory?:bool, evidence_floor?:string }
 *
 * OUTPUT:
 *   { schema, lineage_nodes, edges, waves, missing_prerequisites, terminal_stop_conditions, blockers }
 *
 *   lineage_nodes:          list<{ id, capabilities, dependencies, unlocks, exploratory }>
 *   edges:                  list<{ from, to, type:'depends_on'|'unlocks', symbol }>
 *   waves:                  list<list<string>>  (topological waves, alphabetical within wave)
 *   missing_prerequisites:  list<{ task_id, missing:list<string> }>  (dependency with no provider)
 *   terminal_stop_conditions: list<{ task_id, reason:string }>
 *   blockers:               list<string>
 *
 * BLOCKER CODES:
 *   cycle_detected:<id>                  — task is in a dependency cycle
 *   dangling_prerequisite:<id>:<symbol>  — dependency has no provider (non-exploratory tasks only)
 *
 * Exploratory tasks with evidence_floor set are EXEMPT from dangling_prerequisite blockers; their
 * missing prerequisites are recorded in missing_prerequisites but do not block the compiler.
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasTaskFabricTaskLineageCompiler
{
    public const SCHEMA = 'atlas.task_fabric.task_lineage_compiler.v1';

    /**
     * @param  list<array<string,mixed>>  $specs
     * @return array<string,mixed>
     */
    public function compile(array $specs): array
    {
        // Index by id.
        $byId = [];
        foreach ($specs as $s) {
            if (! is_array($s) || ! isset($s['id'])) {
                continue;
            }
            $id = (string) $s['id'];
            $byId[$id] = [
                'id'             => $id,
                'capabilities'   => array_values(array_map('strval', (array) ($s['capabilities'] ?? []))),
                'dependencies'   => array_values(array_map('strval', (array) ($s['dependencies'] ?? []))),
                'unlocks'        => array_values(array_map('strval', (array) ($s['unlocks'] ?? []))),
                'exploratory'    => (bool) ($s['exploratory'] ?? false),
                'evidence_floor' => (string) ($s['evidence_floor'] ?? ''),
            ];
        }

        // Capability → provider task ids.
        $capabilityProviders = [];  // cap => list<task_id>
        foreach ($byId as $id => $node) {
            foreach ($node['capabilities'] as $cap) {
                $capabilityProviders[$cap][] = $id;
            }
        }

        // Build dependency edges: for each task, for each dependency symbol, find providers.
        $edges               = [];
        $missingPrereqs      = [];   // task_id => list<symbol>
        $blockers            = [];
        $dependsOnIds        = [];   // task_id => list<provider_task_ids>  (for cycle detection)

        foreach ($byId as $id => $node) {
            $missingSymbols = [];
            foreach ($node['dependencies'] as $dep) {
                $providers = $capabilityProviders[$dep] ?? [];
                if ($providers === []) {
                    $missingSymbols[] = $dep;
                    if (! $node['exploratory'] || $node['evidence_floor'] === '') {
                        $blockers[] = 'dangling_prerequisite:'.$id.':'.$dep;
                    }
                } else {
                    foreach ($providers as $provider) {
                        if ($provider !== $id) {
                            $dependsOnIds[$id][] = $provider;
                            $edges[] = [
                                'from'   => $provider,
                                'to'     => $id,
                                'type'   => 'depends_on',
                                'symbol' => $dep,
                            ];
                        }
                    }
                }
            }
            if ($missingSymbols !== []) {
                $missingPrereqs[] = ['task_id' => $id, 'missing' => array_values(array_unique($missingSymbols))];
            }
        }

        // Build unlock edges: task A unlocks capability → tasks B that declare it as dependency.
        foreach ($byId as $id => $node) {
            foreach ($node['unlocks'] as $symbol) {
                $consumers = [];
                foreach ($byId as $otherId => $otherNode) {
                    if ($otherId !== $id && in_array($symbol, $otherNode['dependencies'], true)) {
                        $consumers[] = $otherId;
                        $edges[] = [
                            'from'   => $id,
                            'to'     => $otherId,
                            'type'   => 'unlocks',
                            'symbol' => $symbol,
                        ];
                    }
                }
            }
        }

        // Deduplicate edges (same from/to/type/symbol can appear via both paths).
        $seen  = [];
        $uniqueEdges = [];
        foreach ($edges as $e) {
            $key = $e['from'].':'.$e['to'].':'.$e['type'].':'.$e['symbol'];
            if (! isset($seen[$key])) {
                $seen[$key]     = true;
                $uniqueEdges[]  = $e;
            }
        }
        usort($uniqueEdges, static fn (array $a, array $b): int => strcmp(
            $a['from'].$a['to'].$a['type'].$a['symbol'],
            $b['from'].$b['to'].$b['type'].$b['symbol'],
        ));

        // Topological sort (Kahn's) for waves — only over depends_on relationships.
        [$waves, $cycleIds] = $this->topologicalWaves($byId, $dependsOnIds);
        foreach ($cycleIds as $cycleId) {
            $blockers[] = 'cycle_detected:'.$cycleId;
        }

        // Terminal stop conditions: tasks with no outgoing unlocks to other known tasks.
        $terminalConditions = $this->terminalStopConditions($byId, $cycleIds);

        // Stable sort blockers.
        sort($blockers, SORT_STRING);
        $blockers = array_values(array_unique($blockers));

        // Lineage nodes in stable order.
        $lineageNodes = array_values($byId);
        usort($lineageNodes, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'schema'                   => self::SCHEMA,
            'lineage_nodes'            => $lineageNodes,
            'edges'                    => $uniqueEdges,
            'waves'                    => $waves,
            'missing_prerequisites'    => $missingPrereqs,
            'terminal_stop_conditions' => $terminalConditions,
            'blockers'                 => $blockers,
        ];
    }

    /**
     * Kahn's topological sort → waves (each wave = tasks with all dependencies satisfied).
     * Returns [$waves, $cycleIds].
     *
     * @param  array<string, array<string,mixed>>  $byId
     * @param  array<string, list<string>>          $dependsOnIds
     * @return array{list<list<string>>, list<string>}
     */
    private function topologicalWaves(array $byId, array $dependsOnIds): array
    {
        // Compute in-degree for each node.
        $inDegree = [];
        $children = [];  // provider => list<consumer>
        foreach (array_keys($byId) as $id) {
            $inDegree[$id] = 0;
        }
        foreach ($dependsOnIds as $consumer => $providers) {
            $providers = array_unique($providers);
            $inDegree[$consumer] = count($providers);
            foreach ($providers as $provider) {
                $children[$provider][] = $consumer;
            }
        }

        $waves   = [];
        $settled = [];
        $queue   = [];

        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }
        sort($queue, SORT_STRING);

        while ($queue !== []) {
            $wave = $queue;
            sort($wave, SORT_STRING);
            $waves[] = $wave;
            $nextQueue = [];
            foreach ($wave as $id) {
                $settled[$id] = true;
                foreach ($children[$id] ?? [] as $child) {
                    $inDegree[$child]--;
                    if ($inDegree[$child] === 0) {
                        $nextQueue[] = $child;
                    }
                }
            }
            $queue = array_unique($nextQueue);
            sort($queue, SORT_STRING);
        }

        // Any unsettled node is in a cycle.
        $cycleIds = [];
        foreach (array_keys($byId) as $id) {
            if (! isset($settled[$id])) {
                $cycleIds[] = $id;
            }
        }
        sort($cycleIds, SORT_STRING);

        return [$waves, $cycleIds];
    }

    /**
     * Terminal stop conditions: tasks that no other task depends on AND have no meaningful
     * unlock chain, OR tasks that are cyclic (they will never deliver).
     *
     * @param  array<string, array<string,mixed>>  $byId
     * @param  list<string>                         $cycleIds
     * @return list<array<string,string>>
     */
    private function terminalStopConditions(array $byId, array $cycleIds): array
    {
        // Build set of task_ids that appear in another task's dependencies (i.e., are "consumed").
        $consumed = [];
        foreach ($byId as $node) {
            foreach ($node['dependencies'] as $dep) {
                // Mark capability providers as consumed.
                $consumed[$dep] = true;
            }
        }

        $terminals = [];

        // Cyclic tasks are always terminal (they block the chain).
        foreach ($cycleIds as $id) {
            $terminals[] = ['task_id' => $id, 'reason' => 'cycle_detected'];
        }

        // Non-cyclic tasks whose capabilities are not consumed by any other task and have no unlocks.
        $cycleSet = array_flip($cycleIds);
        foreach ($byId as $id => $node) {
            if (isset($cycleSet[$id])) {
                continue;
            }
            $hasConsumer = false;
            foreach ($node['capabilities'] as $cap) {
                if (isset($consumed[$cap])) {
                    $hasConsumer = true;
                    break;
                }
            }
            $hasUnlocks = $node['unlocks'] !== [];
            if (! $hasConsumer && ! $hasUnlocks) {
                $terminals[] = ['task_id' => $id, 'reason' => 'no_downstream_consumer'];
            }
        }

        usort($terminals, static fn (array $a, array $b): int => strcmp($a['task_id'], $b['task_id']));

        return $terminals;
    }
}
