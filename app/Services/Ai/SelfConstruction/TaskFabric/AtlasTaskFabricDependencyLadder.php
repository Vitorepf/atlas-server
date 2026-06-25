<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure ladder that orders packet specs into BUILDABLE waves with explicit depends_on edges. Workers
 * receive packets in dependency-safe sequence: every consumer arrives in a later wave than every
 * producer it depends on.
 *
 * INPUT: a list of packet specs, each carrying:
 *   { id, produces:list<string>, consumes:list<string>, allowed_files:list<string>, risk_class:string }
 *
 * OUTPUT: { waves:list<list<string>>, depends_on:array<string,list<string>>, blockers:list<string> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ identical output (within-wave order is alphabetical).
 *   - BLOCKERS instead of guessing:
 *       cycle_detected:<id>            — dependency cycle would never finish
 *       missing_producer_for:<symbol>  — a consume edge has no producer in the input
 *       allowed_files_conflict:<path>  — two packets in the same wave would write the same file
 *   - When blockers !== [], waves/edges are still emitted for the consistent subgraph but the caller is
 *     responsible for halting until blockers are resolved.
 */
final class AtlasTaskFabricDependencyLadder
{
    /**
     * @param  list<array{id:string, produces?:list<string>, consumes?:list<string>, allowed_files?:list<string>, risk_class?:string}>  $packetSpecs
     * @return array{waves:list<list<string>>, depends_on:array<string,list<string>>, blockers:list<string>}
     */
    public function ladder(array $packetSpecs): array
    {
        // Index packets by id; collect produces map; track conflicts.
        $byId = [];
        $producesIndex = [];     // symbol => list<packet_id>
        foreach ($packetSpecs as $p) {
            if (! is_array($p) || ! isset($p['id'])) {
                continue;
            }
            $id = (string) $p['id'];
            $byId[$id] = [
                'id' => $id,
                'produces' => array_values(array_map('strval', (array) ($p['produces'] ?? []))),
                'consumes' => array_values(array_map('strval', (array) ($p['consumes'] ?? []))),
                'allowed_files' => array_values(array_map('strval', (array) ($p['allowed_files'] ?? []))),
                'risk_class' => (string) ($p['risk_class'] ?? ''),
            ];
            foreach ($byId[$id]['produces'] as $sym) {
                $producesIndex[$sym][] = $id;
            }
        }

        $blockers = [];
        $dependsOn = [];

        // Build depends_on edges; flag missing producers.
        foreach ($byId as $id => $row) {
            $dependsOn[$id] = [];
            foreach ($row['consumes'] as $sym) {
                if (! isset($producesIndex[$sym])) {
                    $blockers[] = 'missing_producer_for:'.$sym;

                    continue;
                }
                foreach ($producesIndex[$sym] as $producerId) {
                    if ($producerId !== $id && ! in_array($producerId, $dependsOn[$id], true)) {
                        $dependsOn[$id][] = $producerId;
                    }
                }
            }
            sort($dependsOn[$id], SORT_STRING);
        }

        // Topological sort into waves (Kahn-style). Detect cycles.
        $remaining = $byId;
        $waves = [];
        while ($remaining !== []) {
            $thisWave = [];
            foreach ($remaining as $id => $row) {
                $depsLeft = false;
                foreach ($dependsOn[$id] as $dep) {
                    if (isset($remaining[$dep])) {
                        $depsLeft = true;
                        break;
                    }
                }
                if (! $depsLeft) {
                    $thisWave[] = $id;
                }
            }
            if ($thisWave === []) {
                // Cycle — every remaining packet has at least one unresolved dependency on another
                // remaining packet. Flag every still-pending id.
                foreach (array_keys($remaining) as $id) {
                    $blockers[] = 'cycle_detected:'.$id;
                }
                break;
            }
            sort($thisWave, SORT_STRING);
            // Within-wave allowed_files conflict detection.
            $seen = [];
            foreach ($thisWave as $id) {
                foreach ($byId[$id]['allowed_files'] as $f) {
                    if (isset($seen[$f]) && $seen[$f] !== $id) {
                        $blockers[] = 'allowed_files_conflict:'.$f;
                    }
                    $seen[$f] = $id;
                }
            }
            $waves[] = $thisWave;
            foreach ($thisWave as $id) {
                unset($remaining[$id]);
            }
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        // Sort depends_on keys for stable output.
        ksort($dependsOn);

        return ['waves' => $waves, 'depends_on' => $dependsOn, 'blockers' => $blockers];
    }
}
