<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * ACDE Leap 4 — the DETERMINISTIC antichain wave scheduler (the parallelism determinism anchor).
 *
 * Groups an obra's nodes into antichain LEVELS via Kahn layering over the depends_on DAG: every node in
 * level L depends only on nodes in levels < L, so all nodes within a level are MUTUALLY INDEPENDENT and
 * MAY deliver in parallel. The level structure is computed from the machine-declared depends_on edges —
 * NEVER the model's say-so — so fan-out can change only WHEN a node delivers, never WHICH diff certifies.
 *
 * Within a level, two nodes COLLIDE if their write-scopes (target_area + allowed_files) intersect: a
 * parallel apply would race / last-writer-wins, so the executor must serialize a colliding level. This
 * class only DECIDES the schedule (pure: no provider, no git, no mutation); the executor consumes it.
 *
 * Fail-safe: a cyclic or malformed DAG returns acyclic=false with a single serial level (seq order), so a
 * caller that respects {acyclic} degrades to today's strict-serial walk — byte-identical.
 */
final class AtlasObraWaveScheduler
{
    /**
     * Compute the antichain schedule for a node list.
     *
     * @param  list<array<string,mixed>>  $nodes  obra nodes ({id, seq?, depends_on?, target_area?, allowed_files?})
     * @return array{
     *     acyclic: bool,
     *     levels: list<list<string>>,
     *     width: int,
     *     serial_order: list<string>,
     *     level_has_scope_collision: list<bool>,
     *     parallelizable: bool
     * }
     */
    public function schedule(array $nodes): array
    {
        $nodes = array_values($nodes);
        $serialOrder = $this->serialOrder($nodes);

        $byId = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $node;
            }
        }

        [$acyclic, $levels] = $this->kahnLevels($nodes, $byId);
        if (! $acyclic) {
            // Cyclic / malformed => ONE serial level; a caller honouring {acyclic} runs strict-serial (byte-identical).
            return [
                'acyclic' => false,
                'levels' => $serialOrder === [] ? [] : [$serialOrder],
                'width' => $serialOrder === [] ? 0 : 1,
                'serial_order' => $serialOrder,
                'level_has_scope_collision' => $serialOrder === [] ? [] : [true],
                'parallelizable' => false,
            ];
        }

        $collision = [];
        $width = 0;
        foreach ($levels as $level) {
            $collision[] = $this->levelHasScopeCollision($level, $byId);
            $width = max($width, count($level));
        }

        // Parallelizable iff some level has >= 2 mutually-independent, scope-disjoint nodes.
        $parallelizable = false;
        foreach ($levels as $i => $level) {
            if (count($level) >= 2 && ! $collision[$i]) {
                $parallelizable = true;
                break;
            }
        }

        return [
            'acyclic' => true,
            'levels' => $levels,
            'width' => $width,
            'serial_order' => $serialOrder,
            'level_has_scope_collision' => $collision,
            'parallelizable' => $parallelizable,
        ];
    }

    /**
     * Kahn layering: repeatedly emit the set of nodes whose unresolved in-edges are all satisfied. Each
     * emitted set is one antichain level; nodes within a level are sorted by (seq, id) for determinism.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @param  array<string,array<string,mixed>>  $byId
     * @return array{0:bool, 1:list<list<string>>}
     */
    private function kahnLevels(array $nodes, array $byId): array
    {
        $ids = array_keys($byId);
        $remainingDeps = [];
        foreach ($byId as $id => $node) {
            $deps = [];
            foreach ((array) ($node['depends_on'] ?? []) as $d) {
                $d = trim((string) $d);
                // Only edges to DECLARED nodes constrain ordering; a self-edge is ignored (the validator
                // rejects it upstream — here we just must not deadlock on it).
                if ($d !== '' && $d !== $id && isset($byId[$d])) {
                    $deps[$d] = true;
                }
            }
            $remainingDeps[$id] = $deps;
        }

        $levels = [];
        $resolved = [];
        $left = $ids;
        while ($left !== []) {
            $ready = [];
            foreach ($left as $id) {
                $blocked = false;
                foreach (array_keys($remainingDeps[$id]) as $dep) {
                    if (! isset($resolved[$dep])) {
                        $blocked = true;
                        break;
                    }
                }
                if (! $blocked) {
                    $ready[] = $id;
                }
            }
            if ($ready === []) {
                return [false, []]; // a cycle — no node became ready
            }
            usort($ready, fn (string $a, string $b): int => $this->order($byId[$a] ?? [], $a) <=> $this->order($byId[$b] ?? [], $b) ?: strcmp($a, $b));
            $levels[] = $ready;
            foreach ($ready as $id) {
                $resolved[$id] = true;
            }
            $left = array_values(array_filter($left, static fn (string $id): bool => ! isset($resolved[$id])));
        }

        return [true, $levels];
    }

    /** Two nodes in a level collide iff their write-scopes intersect — then the level must serialize. */
    private function levelHasScopeCollision(array $levelIds, array $byId): bool
    {
        $seen = [];
        foreach ($levelIds as $id) {
            foreach ($this->writeScope($byId[$id] ?? []) as $file) {
                if (isset($seen[$file])) {
                    return true;
                }
                $seen[$file] = true;
            }
        }

        return false;
    }

    /**
     * The files a node may write: its target_area + any explicit allowed_files, normalized.
     *
     * @param  array<string,mixed>  $node
     * @return list<string>
     */
    private function writeScope(array $node): array
    {
        $out = [];
        $t = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
        if ($t !== '') {
            $out[$t] = true;
        }
        foreach ((array) ($node['allowed_files'] ?? []) as $f) {
            if (is_string($f) && trim($f) !== '') {
                $out[ltrim(trim($f), '/')] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * The strict-serial node-id order the executor walks today (seq ascending, id tiebreak) — the
     * byte-identical fallback order.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @return list<string>
     */
    private function serialOrder(array $nodes): array
    {
        $withId = array_values(array_filter($nodes, static fn ($n): bool => is_array($n) && trim((string) ($n['id'] ?? '')) !== ''));
        usort($withId, fn (array $a, array $b): int => $this->order($a, (string) $a['id']) <=> $this->order($b, (string) $b['id']) ?: strcmp((string) $a['id'], (string) $b['id']));

        return array_map(static fn (array $n): string => (string) $n['id'], $withId);
    }

    /** @param  array<string,mixed>  $node */
    private function order(array $node, string $id): int
    {
        return isset($node['seq']) ? (int) $node['seq'] : 0;
    }
}
