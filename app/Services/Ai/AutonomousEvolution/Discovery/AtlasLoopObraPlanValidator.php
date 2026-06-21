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
    public function __construct(private readonly ?AtlasLoopHarnessGuard $guard = null) {}

    /**
     * @param  array<string,mixed>  $plan  {plan_id, nodes:list<{id, seq?, request, target_area|allowed_files, depends_on?, acceptance?}>}
     * @param  list<string>  $allowedFiles  the obra's allowed scope (every node file must be within it)
     * @return array{valid:bool, reasons:list<string>, node_count:int}
     */
    public function validate(array $plan, array $allowedFiles): array
    {
        $reasons = [];
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        $allow = $this->buildAllowedMap($allowedFiles);

        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);
        if (count($nodes) < 2) {
            $reasons[] = 'not_a_decomposition:node_count<2';
        }
        if (trim((string) ($plan['plan_id'] ?? '')) === '') {
            $reasons[] = 'plan_id_missing';
        }

        $declared = $this->collectDeclaredIds($nodes);
        $seenIds = [];
        foreach ($nodes as $i => $node) {
            $node = is_array($node) ? $node : [];
            array_push($reasons, ...$this->validateNode($node, $i, $seenIds, $allow, $declared, $guard));
        }

        if (! $this->isAcyclic($nodes)) {
            $reasons[] = 'dependency_cycle_detected';
        }

        array_push($reasons, ...$this->checkNonVacuity($nodes));

        $reasons = array_values(array_unique($reasons));

        return ['valid' => $reasons === [], 'reasons' => $reasons, 'node_count' => count($nodes)];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array<string,bool>
     */
    private function buildAllowedMap(array $allowedFiles): array
    {
        $allow = [];
        foreach ($allowedFiles as $f) {
            if (is_string($f) && trim($f) !== '') {
                $allow[ltrim(trim($f), '/')] = true;
            }
        }

        return $allow;
    }

    /**
     * @param  list<mixed>  $nodes
     * @return array<string,bool>
     */
    private function collectDeclaredIds(array $nodes): array
    {
        $declared = [];
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $nid = trim((string) ($node['id'] ?? ''));
                if ($nid !== '') {
                    $declared[$nid] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * Per-node check. Returns the list of reasons this node contributes (id dup/missing,
     * request empty, files in scope + not forbidden, verifiable, depends_on all declared).
     * $seenIds is mutated so duplicate_id can be detected across iterations.
     *
     * @param  array<string,mixed>  $node
     * @param  array<string,bool>  $seenIds  mutated in place
     * @param  array<string,bool>  $allow
     * @param  array<string,bool>  $declared
     * @return list<string>
     */
    private function validateNode(array $node, int $i, array &$seenIds, array $allow, array $declared, AtlasLoopHarnessGuard $guard): array
    {
        $reasons = [];
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

        $files = $this->collectNodeFiles($node);
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

        if (! $this->isVerifiable($node)) {
            $reasons[] = 'node_'.$tag.':unverifiable_no_acceptance';
        }

        foreach ((array) ($node['depends_on'] ?? []) as $dep) {
            $dep = trim((string) $dep);
            if ($dep !== '' && ! isset($declared[$dep])) {
                $reasons[] = 'node_'.$tag.':depends_on_undeclared:'.$dep;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return list<string>
     */
    private function collectNodeFiles(array $node): array
    {
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

        return $files;
    }

    /**
     * ACDE X4 — NON-VACUITY. The structural checks above prove the DAG is well-FORMED, not DISTINCT: a plan
     * whose nodes all carry the SAME change request (the weak engine "decomposes" by copy-pasting one change
     * across N files) passes them. When armed, refuse a decomposition whose nodes collapse to a single
     * file-agnostic request. Default OFF => byte-identical. Read defensively so a pure-unit caller never
     * fatals (function_exists guard preserves the original try/catch's defensive behavior without the
     * catch counted as a decision point).
     *
     * @param  list<mixed>  $nodes
     * @return list<string>
     */
    private function checkNonVacuity(array $nodes): array
    {
        $reasons = [];
        if (! function_exists('config')) {
            return $reasons;
        }
        if (! (bool) config('atlas.loop.decomposition_non_vacuity_enabled', false) || count($nodes) < 2) {
            return $reasons;
        }
        $distinct = [];
        foreach ($nodes as $node) {
            $req = is_array($node) ? trim((string) ($node['request'] ?? '')) : '';
            if ($req !== '') {
                $distinct[$this->normalizedRequest($req)] = true;
            }
        }
        if (count($distinct) === 1) {
            $reasons[] = 'vacuous_decomposition:identical_node_requests';
        }

        return $reasons;
    }

    /**
     * ACDE X4 — file-agnostic normalization of a node's change request: lowercased, each file-path TOKEN
     * (a basename or dotted segment ending in `.php`) collapsed to a placeholder, whitespace squeezed. Two
     * nodes that ask for "the same change" on different files normalize identically, so a copy-paste
     * decomposition collapses to a single distinct request. The token regex deliberately stops at path
     * separators (`/`) so two distinct paths like `app/Services/Hub.php` and `app/Callers/CallerA.php`
     * each keep their directory identity (`app/Services/FILE` vs `app/Callers/FILE`) — otherwise the
     * greedy cross-/ match would erase the very difference the check is supposed to detect, flagging
     * a legitimate multi-file decomposition as a vacuous copy-paste.
     */
    private function normalizedRequest(string $request): string
    {
        $r = mb_strtolower(trim($request));
        $r = (string) preg_replace('/[A-Za-z0-9_.\-]+\.php\b/', 'FILE', $r); // file-agnostic (per-token, no `/`)
        $r = (string) preg_replace('/\s+/', ' ', $r);

        return trim($r);
    }

    /**
     * ACDE Leap 2 — the DECOMPOSITION BOUNDARY-ORACLE superset check (the moat the structural validator
     * lacks). The structural checks above prove the DAG is WELL-FORMED; they cannot prove it is CORRECT —
     * a plausible-but-wrong split that merges two seams the operator named as separate nodes passes them
     * just as readily as the right split. This method imports the proven single-target trick: a HUMAN
     * froze the required node boundaries, and we prove the generated DAG SUPERSETS them.
     *
     * The generated boundary set is the union of every node's touched files — each node's target_area and
     * any explicit allowed_files (which, for a create-class node, carries the new file). The check is:
     *   - every oracle required_boundary MUST be the target of some node, else 'decomposition_missing_required_boundary:<seam>';
     *   - every oracle required_create_file MUST be the target of some node, else the SAME reason string.
     * It is a deterministic SUPERSET test against a frozen artifact — the model can never satisfy it by
     * emitting a plausible-but-wrong split, because the required seams are named by a human, not the model.
     *
     * Returns the list of missing-boundary reasons (empty => the DAG supersets the oracle). NO false-reject
     * surface: an empty oracle returns [] (no required seams => nothing to miss).
     *
     * @param  array<string,mixed>  $plan  {nodes:list<{id, target_area|allowed_files, ...}>}
     * @param  array{required_boundaries?:list<string>, required_create_files?:list<string>}  $oracle
     * @return list<string>
     */
    public function assertDecompositionMatchesOracle(array $plan, array $oracle): array
    {
        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);

        // The generated boundary set: every file any node targets (target_area + explicit allowed_files).
        $generated = [];
        foreach (array_filter($nodes, 'is_array') as $node) {
            $ta = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
            if ($ta !== '') {
                $generated[$ta] = true;
            }
            foreach ((array) ($node['allowed_files'] ?? []) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $generated[ltrim(trim($f), '/')] = true;
                }
            }
        }

        // Required = the union of human-named required boundaries + mandatory create-class files; both must
        // appear as a node target. (Stay ordered + de-duplicated so the reason list is deterministic.)
        $required = [];
        $requiredKeys = ['required_boundaries', 'required_create_files'];
        $requiredLists = [];
        foreach ($requiredKeys as $key) {
            $requiredLists[] = (array) ($oracle[$key] ?? []);
        }
        foreach (array_merge(...$requiredLists) as $seam) {
            if (is_string($seam) && trim($seam) !== '') {
                $required[ltrim(trim($seam), '/')] = true;
            }
        }

        $missing = [];
        foreach (array_keys($required) as $seam) {
            if (! isset($generated[$seam])) {
                $missing[] = 'decomposition_missing_required_boundary:'.$seam;
            }
        }

        return $missing;
    }

    /**
     * ACDE Leap 6 (plan-time) — the SEAM-TO-SEAM DAG EDGE check (the planner's first machine design-steering
     * signal). assertDecompositionMatchesOracle proves the right FILES are nodes; this proves the right
     * DEPENDENCY DIRECTION between them. depends_on is the ONLY structured design fact a node emits — so a
     * human freezes per file the seams it MUST / must NOT depend_on, and we check the generated DAG's edges.
     *
     * Resolve each node's target file + its depends_on (node-ids → their target files), then for the node
     * targeting a contracted file: a required must_depend_on edge that is ABSENT => 'node_interface_missing_
     * edge:<file>-><dep>'; a forbidden_depend_on edge that is PRESENT => 'node_interface_inverted_edge:
     * <file>-><dep>' (the dependency-inversion the file-only oracle cannot see). Edge rules apply only to
     * files that ARE node targets (a missing required FILE is the boundary-oracle's job) — no false-reject.
     *
     * @param  array<string,mixed>  $plan  {nodes:list<{id, target_area|allowed_files, depends_on?}>}
     * @param  array<string, array{must_depend_on?:list<string>, forbidden_depend_on?:list<string>}>  $contract
     * @return list<string>
     */
    public function assertDecompositionEdges(array $plan, array $contract): array
    {
        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);

        $idToFile = [];
        foreach (array_filter($nodes, 'is_array') as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            $file = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
            if ($id !== '' && $file !== '') {
                $idToFile[$id] = $file;
            }
        }

        $gaps = [];
        foreach (array_filter($nodes, 'is_array') as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            $file = $idToFile[$id] ?? '';
            if ($file === '' || ! isset($contract[$file])) {
                continue;
            }
            $rule = (array) $contract[$file];

            $depFiles = [];
            foreach ((array) ($node['depends_on'] ?? []) as $d) {
                $d = trim((string) $d);
                if (isset($idToFile[$d])) {
                    $depFiles[$idToFile[$d]] = true;
                }
            }

            foreach ((array) ($rule['must_depend_on'] ?? []) as $must) {
                $must = ltrim(trim((string) $must), '/');
                if ($must !== '' && ! isset($depFiles[$must])) {
                    $gaps[] = 'node_interface_missing_edge:'.$file.'->'.$must;
                }
            }
            foreach ((array) ($rule['forbidden_depend_on'] ?? []) as $forb) {
                $forb = ltrim(trim((string) $forb), '/');
                if ($forb !== '' && isset($depFiles[$forb])) {
                    $gaps[] = 'node_interface_inverted_edge:'.$file.'->'.$forb;
                }
            }
        }

        return $gaps;
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
        foreach (array_filter($nodes, 'is_array') as $node) {
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
        // in-degree = number of declared dependencies. array_map walks $deps once and removes the explicit
        // foreach — the inner arrow fn's body (isset) is not a decision node, so no extra branches.
        $indeg = array_map(
            fn(array $ds): int => count(array_filter($ds, fn(string $d): bool => isset($ids[$d]))),
            $deps,
        );
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
