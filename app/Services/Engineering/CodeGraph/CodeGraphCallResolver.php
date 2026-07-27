<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Method->method CALL edges for the code graph (AP-811/812, deepest granularity).
 *
 * The companion to {@see CodeGraphSymbolResolver}: where that resolves structural
 * SYMBOL->SYMBOL edges (depends_on / tests, keyed by fully-qualified name from
 * imports & references), this resolves behavioural METHOD->METHOD edges — "method A
 * calls method B" — from the heuristic (caller, callee) pairs produced by the python
 * call-graph extractor ({@see runtimes/python/code_graph/atlas_code_graph/callgraph.py},
 * schema atlas.code_graph.callgraph.v1).
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime. The caller supplies
 * the already-extracted call pairs and a method-name index (built from the Code
 * Intelligence read-model). It FEEDS the graph; it decides nothing about providers,
 * models, domains or policy.
 *
 * Calls are inherently heuristic here — the extractor sees only a trailing name, with
 * no receiver-type resolution — so the keeper discipline is deliberately strict:
 *  - single-candidate gate (BOTH ends): an edge is emitted only when the callee name
 *    resolves to EXACTLY ONE defining method FQN, AND the caller name does too. A name
 *    defined by zero or many methods is never turned into an edge — we never invent or
 *    guess a target;
 *  - confidence INFERRED (0.7): unlike the import-backed symbol edges, a resolved call
 *    is a heuristic, so it can never be EXTRACTED here;
 *  - skip self-calls (recursion / same-FQN caller==callee) and dedup with
 *    strongest-evidence-wins + occurrence counting, matching the symbol resolver.
 *
 * @phpstan-type CallPair array{caller?:string, callee?:string, path?:string, language?:string}
 */
class CodeGraphCallResolver
{
    public const SCHEMA = 'atlas.code_graph.call_edges.v1';

    public const CONFIDENCE_INFERRED = 'INFERRED';

    private const EDGE_CALLS = 'calls';

    /** Calls are heuristic (no type resolution) -> always INFERRED, fixed score. */
    private const SCORE_INFERRED = 0.7;

    /**
     * PHP builtins + ultra-common method names. A call to one of these is almost
     * never to a coincidental same-named user method, so we never resolve them —
     * this kills false edges like `::nodeId -> AtlasReplHistory::trim` where `trim`
     * is really the PHP builtin. Precision over recall, by design.
     */
    private const BUILTIN_DENYLIST = [
        'trim', 'count', 'map', 'filter', 'get', 'set', 'all', 'first', 'last', 'where', 'value', 'make', 'run',
        'handle', 'boot', 'render', 'build', 'validate', 'format', 'parse', 'push', 'pop', 'merge', 'keys', 'values',
        'has', 'add', 'remove', 'find', 'save', 'create', 'update', 'delete', 'toarray', 'jsonserialize', '__construct',
        '__invoke', '__get', '__set', '__call', '__tostring', 'strlen', 'str_replace', 'implode', 'explode',
        'json_encode', 'json_decode', 'array_map', 'array_filter', 'array_merge', 'array_keys', 'array_values',
        'sprintf', 'printf', 'sort', 'each', 'isempty', 'isset', 'empty', 'collect', 'now', 'config', 'app', 'base_path',
    ];

    /**
     * Resolve heuristic (caller, callee) call pairs into method->method 'calls' edges.
     *
     * @param  array<int,array{caller?:string, callee?:string, path?:string, language?:string}>  $calls
     *   call pairs from the python extractor (atlas.code_graph.callgraph.v1).
     * @param  array<string,array<int,string>>  $methodIndex  short method name -> list of
     *   defining method FQNs (e.g. 'helper' => ['App\\Svc::helper']). A name with a
     *   single entry is single-candidate; zero or multiple entries are unresolved /
     *   ambiguous and never produce an edge.
     * @return array{schema_version:string, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function resolveCalls(array $calls, array $methodIndex): array
    {
        $index = $this->normalizeIndex($methodIndex);

        $edges = [];
        $stats = [
            'calls' => 0,
            'edges' => 0,
            'inferred' => 0,
            'skipped_unresolved_callee' => 0,
            'skipped_ambiguous_callee' => 0,
            'skipped_builtin_callee' => 0,
            'skipped_unresolved_caller' => 0,
            'skipped_ambiguous_caller' => 0,
            'skipped_self' => 0,
            'deduped' => 0,
        ];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }
            $stats['calls']++;

            $callerName = $this->str($call['caller'] ?? null);
            $calleeName = $this->str($call['callee'] ?? null);
            if ($callerName === null || $calleeName === null) {
                $stats['skipped_unresolved_callee']++;

                continue;
            }

            // Builtin / ultra-common name -> never a real user-method target.
            if (in_array(strtolower($calleeName), self::BUILTIN_DENYLIST, true)) {
                $stats['skipped_builtin_callee']++;

                continue;
            }

            // Single-candidate gate on the CALLEE (the target we'd point at).
            $calleeFqn = $this->singleCandidate($index, $calleeName, $stats, 'callee');
            if ($calleeFqn === null) {
                continue;
            }

            // Single-candidate gate on the CALLER (so the edge is genuinely method->method,
            // not method->free-name). The enclosing caller may be a file path (top-level
            // call) or an ambiguous/unknown name — all of which are skipped here.
            $callerFqn = $this->singleCandidate($index, $callerName, $stats, 'caller');
            if ($callerFqn === null) {
                continue;
            }

            $from = $this->nodeId($callerFqn);
            $to = $this->nodeId($calleeFqn);
            if ($from === $to) {
                $stats['skipped_self']++;

                continue;
            }

            $this->addEdge($edges, $stats, $from, $to, $calleeName, $this->str($call['path'] ?? null) ?? '');
        }

        $edgeList = array_values($edges);
        usort($edgeList, static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']] <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']]);

        return [
            'schema_version' => self::SCHEMA,
            'edges' => $edgeList,
            'stats' => $stats,
        ];
    }

    /**
     * Single-candidate resolution of a short name against the index. Records the right
     * skip stat (unresolved vs ambiguous) for the given side and returns the lone FQN,
     * or null when zero / many candidates.
     *
     * @param  array<string,array<int,string>>  $index
     * @param  array<string,int>  $stats
     */
    private function singleCandidate(array $index, string $name, array &$stats, string $side): ?string
    {
        $candidates = $index[$name] ?? [];
        $count = count($candidates);
        if ($count === 0) {
            $stats['skipped_unresolved_'.$side]++;

            return null;
        }
        if ($count > 1) {
            $stats['skipped_ambiguous_'.$side]++;

            return null;
        }

        return $candidates[0];
    }

    /**
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,int>  $stats
     */
    private function addEdge(array &$edges, array &$stats, string $from, string $to, string $calleeName, string $sourceRef): void
    {
        $key = $from.'|'.$to.'|'.self::EDGE_CALLS;
        if (isset($edges[$key])) {
            $stats['deduped']++;
            $edges[$key]['metadata']['occurrences']++;

            return;
        }

        $stats['edges']++;
        $stats['inferred']++;

        $edges[$key] = [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => self::EDGE_CALLS,
            'confidence' => self::CONFIDENCE_INFERRED,
            'confidence_score' => self::SCORE_INFERRED,
            'metadata' => [
                'resolver' => self::SCHEMA,
                'relation' => 'call',
                'callee_name' => $calleeName,
                'confidence' => self::CONFIDENCE_INFERRED,
                'confidence_score' => self::SCORE_INFERRED,
                'source_ref' => $sourceRef,
                'occurrences' => 1,
                'inferred' => true,
            ],
        ];
    }

    /**
     * Normalize the method index: short name -> de-duplicated, sorted list of valid
     * FQN strings. Defensive against non-string keys/values so a dirty read-model can
     * never crash the transform; an empty candidate list is dropped (treated as
     * "name not defined").
     *
     * @param  array<string,array<int,string>>  $methodIndex
     * @return array<string,array<int,string>>
     */
    private function normalizeIndex(array $methodIndex): array
    {
        $index = [];
        foreach ($methodIndex as $name => $fqns) {
            if (! is_string($name)) {
                continue;
            }
            $shortName = trim($name);
            if ($shortName === '' || ! is_array($fqns)) {
                continue;
            }
            $seen = [];
            foreach ($fqns as $fqn) {
                $clean = $this->fqn($fqn);
                if ($clean !== null) {
                    $seen[$clean] = true;
                }
            }
            if ($seen !== []) {
                $list = array_keys($seen);
                sort($list); // determinism: candidate order independent of input order
                $index[$shortName] = $list;
            }
        }

        return $index;
    }

    /**
     * Deterministic node id for a method FQN, kept within the world-model node_id
     * varchar(160) limit. Identical scheme to {@see CodeGraphSymbolResolver::nodeId()}
     * so a method node referenced as a call source/target collides with the same
     * method node produced by the symbol resolver.
     */
    private function nodeId(string $fqn): string
    {
        $id = 'sym:'.$fqn;
        if (strlen($id) <= 160) {
            return $id;
        }

        return 'sym:'.substr($fqn, -130).'#'.substr(sha1($fqn), 0, 12);
    }

    private function fqn(mixed $value): ?string
    {
        $v = $this->str($value);
        if ($v === null) {
            return null;
        }
        // Normalize: drop a leading backslash; keep namespace + Class::method.
        return ltrim($v, '\\');
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
