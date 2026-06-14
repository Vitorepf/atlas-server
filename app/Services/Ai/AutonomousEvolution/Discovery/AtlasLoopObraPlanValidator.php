<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;

/**
 * The deterministic SAFETY GATE on obra DECOMPOSITION — the ungameable core that makes ENORMOUS,
 * many-node obras safe to execute autonomously. A big feature/refactor is decomposed (by the provider,
 * or a future planner) into a dependency DAG of nodes; before the executor spends a single token, THIS
 * validator proves the plan is WELL-FORMED + SCOPED + SAFE. A malformed/unsafe decomposition is
 * rejected with reasons — never executed.
 *
 * Checks (all deterministic, no provider, no DB, no mutation):
 *   - >=2 nodes (a single-node "decomposition" is not one — that is the single-file lane);
 *   - unique, non-empty node ids; each node has a non-empty request and a target file;
 *   - every node file is WITHIN the obra's allowed scope (no node escapes the cluster);
 *   - NO node touches a forbidden self-target (pétreo — the loop never decomposes onto its own gates);
 *   - every node is VERIFIABLE: it carries an acceptance (commands / frozen tests / complexity_proof) —
 *     a node with no way to prove it landed is rejected (no unfalsifiable steps);
 *   - the depends_on graph is ACYCLIC and references only declared nodes (a runnable topological order
 *     exists) — so the executor's seq walk is sound.
 */
final class AtlasLoopObraPlanValidator
{
    public function __construct(private readonly ?AtlasLoopHarnessGuard $guard = null)
    {
    }

    /**
     * @param  array<string,mixed>  $plan         {plan_id, nodes:list<{id, seq?, request, target_area|allowed_files, depends_on?, acceptance?}>}
     * @param  list<string>         $allowedFiles the obra's allowed scope (every node file must be within it)
     * @return array{valid:bool, reasons:list<string>, node_count:int}
     */
    public function validate(array $plan, array $allowedFiles): array
    {
        $reasons = [];
        $guard = $this->guard ?? new AtlasLoopHarnessGuard();
        $allow = [];
        foreach ($allowedFiles as $f) {
            if (is_string($f) && trim($f) !== '') {
                $allow[ltrim(trim($f), '/')] = true;
            }
        }

        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);
        if (count($nodes) < 2) {
            $reasons[] = 'not_a_decomposition:node_count<2';
        }
        if (trim((string) ($plan['plan_id'] ?? '')) === '') {
            $reasons[] = 'plan_id_missing';
        }

        $seenIds = [];
        $declared = [];
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $nid = trim((string) ($node['id'] ?? ''));
                if ($nid !== '') {
                    $declared[$nid] = true;
                }
            }
        }

        foreach ($nodes as $i => $node) {
            $node = is_array($node) ? $node : [];
            $nid = trim((string) ($node['id'] ?? ''));
            $tag = $nid !== '' ? $nid : ('#'.$i);
            if ($nid === '') {
                $reasons[] = 'node_'.$tag.':id_missing';
            } elseif (isset($seenIds[$nid])) {
                $reasons[] = 'node_'.$tag.':duplicate_id';
            }
            $seenIds[$nid] = true;

            if (trim((string) ($node['request'] ?? '')) === '') {
                $reasons[] = 'node_'.$tag.':request_empty';
            }

            // Files this node touches: explicit allowed_files, else its single target_area.
            $files = [];
            foreach ((array) ($node['allowed_files'] ?? []) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $files[] = ltrim(trim($f), '/');
                }
            }
            $ta = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
            if ($ta !== '') {
                $files[] = $ta;
            }
            if ($files === []) {
                $reasons[] = 'node_'.$tag.':no_target_file';
            }
            foreach (array_unique($files) as $file) {
                if ($allow !== [] && ! isset($allow[$file])) {
                    $reasons[] = 'node_'.$tag.':file_outside_allowed_scope:'.$file;
                }
                if ($guard->isForbiddenSelfTarget($file)) {
                    $reasons[] = 'node_'.$tag.':forbidden_self_target:'.$file;
                }
            }

            // VERIFIABLE: an acceptance (commands / frozen tests / complexity_proof) must exist.
            if (! $this->isVerifiable($node)) {
                $reasons[] = 'node_'.$tag.':unverifiable_no_acceptance';
            }

            // depends_on must reference DECLARED nodes only (no dangling edges).
            foreach ((array) ($node['depends_on'] ?? []) as $dep) {
                $dep = trim((string) $dep);
                if ($dep !== '' && ! isset($declared[$dep])) {
                    $reasons[] = 'node_'.$tag.':depends_on_undeclared:'.$dep;
                }
            }
        }

        if (! $this->isAcyclic($nodes)) {
            $reasons[] = 'dependency_cycle_detected';
        }

        $reasons = array_values(array_unique($reasons));

        return ['valid' => $reasons === [], 'reasons' => $reasons, 'node_count' => count($nodes)];
    }

    /** A node is verifiable iff it declares some acceptance contract the executor/certifier can run. */
    private function isVerifiable(array $node): bool
    {
        $acc = is_array($node['acceptance'] ?? null) ? (array) $node['acceptance'] : [];
        if (array_filter((array) ($acc['commands'] ?? []), static fn ($c): bool => is_string($c) && trim($c) !== '')) {
            return true;
        }
        if (array_filter((array) ($node['frozen_tests'] ?? []), 'is_array')) {
            return true;
        }

        return (bool) ($node['complexity_proof'] ?? $acc['complexity_proof'] ?? false);
    }

    /**
     * Kahn topological sort over the depends_on edges among DECLARED nodes — true iff a runnable order
     * exists (no cycle). Edges to undeclared nodes are ignored here (flagged separately above).
     *
     * @param  list<mixed>  $nodes
     */
    private function isAcyclic(array $nodes): bool
    {
        $ids = [];
        $deps = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $ids[$id] = true;
            $deps[$id] = [];
            foreach ((array) ($node['depends_on'] ?? []) as $d) {
                $d = trim((string) $d);
                if ($d !== '') {
                    $deps[$id][] = $d;
                }
            }
        }
        // in-degree = number of declared dependencies.
        $indeg = [];
        foreach ($deps as $id => $ds) {
            $indeg[$id] = count(array_filter($ds, static fn (string $d): bool => isset($ids[$d])));
        }
        $queue = array_keys(array_filter($indeg, static fn (int $d): bool => $d === 0));
        $visited = 0;
        while ($queue !== []) {
            $n = array_shift($queue);
            $visited++;
            foreach ($deps as $id => $ds) {
                if (in_array($n, $ds, true)) {
                    $indeg[$id]--;
                    if ($indeg[$id] === 0) {
                        $queue[] = $id;
                    }
                }
            }
        }

        return $visited === count($ids);
    }
}
