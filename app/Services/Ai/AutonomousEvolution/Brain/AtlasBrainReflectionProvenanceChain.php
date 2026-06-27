<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * REFLECTION PROVENANCE CHAIN — comprehension-deepening organ. Given a tail of reflections each
 * with a cycle_id and optional parent_cycle_id, walks the chain backward from a target cycle to
 * its root (or until a cycle is missing). Returns the lineage list (root → target order). Lets
 * operators see "which prior cycles produced the conditions that led to cycle X".
 *
 * Cycle-safe: visited set prevents infinite loops if data is corrupted. Pure walk, no IO. Pétreo.
 */
final class AtlasBrainReflectionProvenanceChain
{
    public const SCHEMA = 'atlas.brain.reflection_provenance_chain.v1';

    private const MAX_DEPTH = 100;

    /**
     * @param  list<array{cycle_id?:string, parent_cycle_id?:?string}>  $tail
     * @return array{schema:string, target:string, chain:list<string>, truncated:bool}
     */
    public function chain(array $tail, string $target): array
    {
        $byId = [];
        foreach ($tail as $r) {
            $id = (string) ($r['cycle_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $r;
        }

        $chain = [];
        $visited = [];
        $current = $target;
        $truncated = false;
        while (true) {
            if (count($chain) >= self::MAX_DEPTH) {
                $truncated = true;
                break;
            }
            if ($current === '' || ! isset($byId[$current]) || isset($visited[$current])) {
                if ($current !== '' && ! isset($visited[$current])) {
                    array_unshift($chain, $current);
                }
                break;
            }
            $visited[$current] = true;
            array_unshift($chain, $current);
            $parent = (string) ($byId[$current]['parent_cycle_id'] ?? '');
            $current = $parent;
        }

        return ['schema' => self::SCHEMA, 'target' => $target, 'chain' => $chain, 'truncated' => $truncated];
    }
}
