<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure ladder that orders packet specs into BUILDABLE waves with explicit depends_on edges. Workers
 * receive packets in dependency-safe sequence: every consumer arrives in a later wave than every
 * producer it depends on.
 *
 * RUNG TYPE PRIORITY: packets are ordered by rung_type within waves — foundation_repair always
 * precedes proof_gate, cleanup, and feature_expansion. A feature_expansion with no earlier rung
 * in the input is blocked_by_prerequisite.
 *
 * INPUT: a list of packet specs, each carrying:
 *   { id, produces:list<string>, consumes:list<string>, allowed_files:list<string>, risk_class:string,
 *     rung_type?:string }
 *
 * OUTPUT: { waves, depends_on, blockers, conflict_reasons, ordered_rungs, blocked_tasks, unlock_reason }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ identical output (within-wave order is alphabetical,
 *     then by rung_type priority).
 *   - BLOCKERS instead of guessing:
 *       cycle_detected:<id>            — dependency cycle would never finish
 *       missing_producer_for:<symbol>  — a consume edge has no producer in the input
 *       allowed_files_conflict:<path>  — two packets in the same wave would write the same file
 *   - When blockers !== [], waves/edges are still emitted for the consistent subgraph.
 */
final class AtlasTaskFabricDependencyLadder
{
    public const RUNG_FOUNDATION_REPAIR = 'foundation_repair';
    public const RUNG_PROOF_GATE       = 'proof_gate';
    public const RUNG_CLEANUP          = 'cleanup';
    public const RUNG_FEATURE_EXPANSION = 'feature_expansion';

    /** Higher number = lower priority (placed in later position). */
    private const RUNG_PRIORITY = [
        self::RUNG_FOUNDATION_REPAIR => 0,
        self::RUNG_PROOF_GATE       => 1,
        self::RUNG_CLEANUP          => 2,
        self::RUNG_FEATURE_EXPANSION => 3,
    ];

    /** Earlier rungs that a feature_expansion requires to be present in the input. */
    private const FEATURE_PREREQUISITE_RUNGS = [
        self::RUNG_FOUNDATION_REPAIR,
        self::RUNG_PROOF_GATE,
        self::RUNG_CLEANUP,
    ];

    /** @var list<string> All rung types in priority order. */
    private const RUNG_TYPES_ORDERED = [
        self::RUNG_FOUNDATION_REPAIR,
        self::RUNG_PROOF_GATE,
        self::RUNG_CLEANUP,
        self::RUNG_FEATURE_EXPANSION,
    ];

    /**
     * @param  list<array{id:string, produces?:list<string>, consumes?:list<string>, allowed_files?:list<string>, risk_class?:string, rung_type?:string}>  $packetSpecs
     * @return array{waves:list<list<string>>, depends_on:array<string,list<string>>, blockers:list<string>, ordered_rungs:list<string>, blocked_tasks:list<array<mixed>>, unlock_reason:?string}
     */
    public function ladder(array $packetSpecs): array
    {
        $byId = [];
        $producesIndex = [];
        $rungTypesById = [];

        foreach ($packetSpecs as $p) {
            if (! is_array($p) || ! isset($p['id'])) {
                continue;
            }
            $id = (string) $p['id'];
            $rungType = trim((string) ($p['rung_type'] ?? self::RUNG_FEATURE_EXPANSION));
            if (! isset(self::RUNG_PRIORITY[$rungType])) {
                $rungType = self::RUNG_FEATURE_EXPANSION;
            }
            $rungTypesById[$id] = $rungType;
            $prereqEvidence = array_key_exists('prerequisite_evidence', $p)
                ? array_values(array_map('strval', (array) $p['prerequisite_evidence']))
                : null;
            $producerPins = [];
            foreach ((array) ($p['producer_pins'] ?? []) as $sym => $producerId) {
                $producerPins[(string) $sym] = (string) $producerId;
            }
            $byId[$id] = [
                'id' => $id,
                'produces' => array_values(array_map('strval', (array) ($p['produces'] ?? []))),
                'consumes' => array_values(array_map('strval', (array) ($p['consumes'] ?? []))),
                'allowed_files' => array_values(array_map('strval', (array) ($p['allowed_files'] ?? []))),
                'risk_class' => (string) ($p['risk_class'] ?? ''),
                'prerequisite_evidence' => $prereqEvidence,
                'producer_pins' => $producerPins,
                'rung_type' => $rungType,
            ];
            foreach ($byId[$id]['produces'] as $sym) {
                $producesIndex[$sym][] = $id;
            }
        }

        $blockers = [];
        $dependsOn = [];

        foreach ($byId as $id => $row) {
            $dependsOn[$id] = [];
            foreach ($row['consumes'] as $sym) {
                if (! isset($producesIndex[$sym])) {
                    $blockers[] = 'missing_producer_for:'.$sym;
                    continue;
                }
                $candidateProducers = array_values(array_filter($producesIndex[$sym], static fn (string $pid): bool => $pid !== $id));
                $pinnedProducer = $row['producer_pins'][$sym] ?? null;
                if (count($candidateProducers) > 1 && ($pinnedProducer === null || ! in_array($pinnedProducer, $candidateProducers, true))) {
                    $blockers[] = 'ambiguous_producer:'.$sym;
                }
                $edgeProducers = ($pinnedProducer !== null && in_array($pinnedProducer, $candidateProducers, true))
                    ? [$pinnedProducer]
                    : $candidateProducers;
                foreach ($edgeProducers as $producerId) {
                    $producerEvidence = $byId[$producerId]['prerequisite_evidence'] ?? null;
                    if ($producerEvidence !== null && $producerEvidence === []) {
                        $blockers[] = 'missing_prerequisite_evidence:'.$sym;
                    }
                    if (! in_array($producerId, $dependsOn[$id], true)) {
                        $dependsOn[$id][] = $producerId;
                    }
                }
            }
            sort($dependsOn[$id], SORT_STRING);
        }

        // Topological sort into waves (Kahn-style). Detect cycles.
        $remaining = $byId;
        $waves = [];
        $conflictReasons = [];
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
                foreach (array_keys($remaining) as $id) {
                    $blockers[] = 'cycle_detected:'.$id;
                }
                break;
            }
            // Within-wave sort: by rung_type priority first, then alphabetically.
            usort($thisWave, function (string $a, string $b) use ($rungTypesById): int {
                $pa = self::RUNG_PRIORITY[$rungTypesById[$a] ?? self::RUNG_FEATURE_EXPANSION] ?? 3;
                $pb = self::RUNG_PRIORITY[$rungTypesById[$b] ?? self::RUNG_FEATURE_EXPANSION] ?? 3;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
                return strcmp($a, $b);
            });
            // Within-wave allowed_files conflict detection.
            $seen = [];
            $waveIndex = count($waves);
            foreach ($thisWave as $id) {
                foreach ($byId[$id]['allowed_files'] as $f) {
                    if (isset($seen[$f]) && $seen[$f] !== $id) {
                        $blockers[] = 'allowed_files_conflict:'.$f;
                        $conflictPair = [$seen[$f], $id];
                        sort($conflictPair, SORT_STRING);
                        $conflictReasons[] = [
                            'reason_code' => 'file_collision',
                            'file'        => $f,
                            'packet_ids'  => $conflictPair,
                            'wave_index'  => $waveIndex,
                        ];
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
        ksort($dependsOn);

        // Ordered rungs: deduplicated rung types in execution order.
        $orderedRungs = [];
        foreach (self::RUNG_TYPES_ORDERED as $rung) {
            foreach ($rungTypesById as $id => $rt) {
                if ($rt === $rung) {
                    $orderedRungs[] = $rung;
                    break;
                }
            }
        }

        // Blocked tasks: feature_expansion packets whose required earlier rungs are absent.
        $blockedTasks = [];
        $presentRungs = array_unique(array_values($rungTypesById));
        foreach ($byId as $id => $row) {
            if ($row['rung_type'] !== self::RUNG_FEATURE_EXPANSION) {
                continue;
            }
            $missingPrereqs = [];
            foreach (self::FEATURE_PREREQUISITE_RUNGS as $prereqType) {
                if (! in_array($prereqType, $presentRungs, true)) {
                    $missingPrereqs[] = $prereqType;
                }
            }
            if ($missingPrereqs !== []) {
                $blockedTasks[] = [
                    'packet_id' => $id,
                    'missing_prerequisite_rungs' => $missingPrereqs,
                ];
            }
        }

        // Unlock reason: explains what's needed for the first blocked task.
        $unlockReason = null;
        if ($blockedTasks !== []) {
            $first = $blockedTasks[0];
            $unlockReason = "{$first['packet_id']} blocked — requires " . implode(', ', $first['missing_prerequisite_rungs']) . ' before feature expansion.';
        }

        return [
            'waves'           => $waves,
            'depends_on'      => $dependsOn,
            'blockers'        => $blockers,
            'conflict_reasons' => $conflictReasons,
            'ordered_rungs'   => $orderedRungs,
            'blocked_tasks'   => $blockedTasks,
            'unlock_reason'   => $unlockReason,
        ];
    }
}
