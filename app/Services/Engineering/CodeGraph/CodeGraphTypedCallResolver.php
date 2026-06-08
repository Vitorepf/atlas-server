<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * TYPE-AWARE method->method CALL edges for the code graph (AP-811/812, densifying pass).
 *
 * The precise counterpart to {@see CodeGraphCallResolver}. Where that resolver sees only a
 * trailing callee NAME and must gate hard on single-candidate / builtin denylist (so every
 * edge it dares to emit is merely INFERRED, score 0.7), this resolver consumes the richer
 * TYPED call records produced by the type-aware extractor (slice 1):
 *
 *     {path, caller_class, caller_method, callee_name, receiver}
 *
 * where `receiver` carries the resolved receiver TYPE intent:
 *   - "this" / "self" / "static" -> the enclosing class (`$this->m()`, `self::m()`, `static::m()`)
 *   - "parent"                   -> the parent class (`parent::m()`)
 *   - {static_class: Name}       -> an explicit class reference (`Name::m()`)
 *   - "unknown"                  -> a dynamic / un-typed receiver (`$x->m()` with no known type)
 *
 * Because the receiver type is known, the resolved callee class is CERTAIN, so these edges are
 * EXTRACTED (score 1.0) — the densifying, high-confidence layer of the call graph. The
 * heuristic name-only resolver stays as the INFERRED fallback for what type resolution can't reach.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime, no policy. It FEEDS the graph;
 * it decides nothing. The caller supplies the typed call records (slice 1), the per-file import
 * map and a class index, all derived from the Code Intelligence read-model.
 *
 * Keeper discipline kept from the symbol/call resolvers:
 *   - existence gate on explicit `Name::m()`: the resolved class FQN must exist in $classIndex,
 *     else the static reference is skipped — we never invent a target;
 *   - inheritance is honoured, not guessed: `$this->m()` where the enclosing class doesn't declare
 *     `m` still resolves to caller_class::m (the most-derived owner the graph keys on) and is marked
 *     metadata.inherited=true rather than dropped — the type is still certain;
 *   - node ids identical to {@see CodeGraphSymbolResolver::nodeId()} / {@see CodeGraphCallResolver::nodeId()}
 *     so a method node here collides with the same method node from the other resolvers;
 *   - skip self-calls (recursion) and dedup with occurrence counting; deterministic edge order.
 *
 * @phpstan-type TypedCall array{path?:string, caller_class?:string, caller_method?:string, callee_name?:string, receiver?:mixed}
 */
class CodeGraphTypedCallResolver
{
    public const SCHEMA = 'atlas.code_graph.typed_call_edges.v1';

    /** Receiver type is known -> the callee class is certain -> EXTRACTED. */
    public const CONFIDENCE_EXTRACTED = 'EXTRACTED';

    private const EDGE_CALLS = 'calls';

    /** Type-resolved calls are certain -> fixed top score. */
    private const SCORE_EXTRACTED = 1.0;

    /** Receiver tokens that mean "the enclosing class itself". */
    private const SELF_RECEIVERS = ['this', 'self', 'static'];

    /**
     * Resolve TYPED call records into type-certain method->method 'calls' edges.
     *
     * @param  array<int,array{path?:string, caller_class?:string, caller_method?:string, callee_name?:string, receiver?:mixed}>  $calls
     *   typed call records from slice 1 (the type-aware extractor).
     * @param  array<string,array<string,string>>  $importsByFile
     *   file path -> {alias => FQN} import map (e.g. 'app/A.php' => ['Helper' => 'App\\Y\\Helper']).
     * @param  array<string,array{parent?:?string, methods?:array<int,string>, namespace?:string}>  $classIndex
     *   class FQN -> {parent: ?classFQN, methods: [shortName,...], namespace: string}, from Code Intelligence.
     * @return array{schema_version:string, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function resolve(array $calls, array $importsByFile, array $classIndex): array
    {
        $classIndex = $this->normalizeClassIndex($classIndex);
        $importsByFile = $this->normalizeImports($importsByFile);

        $edges = [];
        $stats = [
            'calls' => 0,
            'edges' => 0,
            'extracted' => 0,
            'skipped_dynamic' => 0,
            'skipped_unresolved_parent' => 0,
            'skipped_unresolved_static' => 0,
            'skipped_self' => 0,
            'deduped' => 0,
        ];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }
            $stats['calls']++;

            $callerClass = $this->fqn($call['caller_class'] ?? null);
            $callerMethod = $this->str($call['caller_method'] ?? null);
            $calleeName = $this->str($call['callee_name'] ?? null);
            $receiver = $call['receiver'] ?? null;
            $path = $this->str($call['path'] ?? null) ?? '';

            // A typed call needs an enclosing method (the edge source) and a callee name.
            if ($callerClass === null || $callerMethod === null || $calleeName === null) {
                $stats['skipped_dynamic']++;

                continue;
            }

            // Resolve the callee's owning class FQN from the receiver TYPE.
            $resolution = $this->resolveTargetClass($receiver, $callerClass, $calleeName, $path, $importsByFile, $classIndex, $stats);
            if ($resolution === null) {
                continue; // the appropriate skip stat was already recorded
            }
            [$targetClass, $inherited] = $resolution;

            $callerFqn = $callerClass.'::'.$callerMethod;
            $calleeFqn = $targetClass.'::'.$calleeName;

            $from = $this->nodeId($callerFqn);
            $to = $this->nodeId($calleeFqn);
            if ($from === $to) {
                $stats['skipped_self']++;

                continue;
            }

            $this->addEdge($edges, $stats, $from, $to, $calleeName, $this->receiverLabel($receiver, $targetClass), $inherited, $path);
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
     * Resolve the callee's owning class FQN from the receiver type.
     *
     * @param  array<string,array<string,string>>  $importsByFile
     * @param  array<string,array{parent:?string, methods:array<string,bool>, namespace:string}>  $classIndex
     * @param  array<string,int>  $stats
     * @return array{0:string,1:bool}|null  [target class FQN, inherited?] or null when skipped.
     */
    private function resolveTargetClass(mixed $receiver, string $callerClass, string $calleeName, string $path, array $importsByFile, array $classIndex, array &$stats): ?array
    {
        // Explicit class reference: receiver is {static_class: Name}.
        $staticName = $this->staticClassName($receiver);
        if ($staticName !== null) {
            $resolved = $this->resolveClassName($staticName, $path, $callerClass, $importsByFile, $classIndex);
            if ($resolved === null || ! isset($classIndex[$resolved])) {
                // existence gate: never point at a class the index doesn't know.
                $stats['skipped_unresolved_static']++;

                return null;
            }

            return [$resolved, false];
        }

        $token = is_string($receiver) ? strtolower(trim($receiver)) : '';

        // $this->m() / self::m() / static::m() -> the enclosing class. The type is certain even
        // when the method is inherited (not declared on caller_class); we still resolve to
        // caller_class (the most-derived owner the graph keys on) and flag inherited=true so a
        // downstream consumer can see the method is not locally declared. Only flag when the
        // class is actually in the index AND lacks the method — an unknown class can't be judged.
        if (in_array($token, self::SELF_RECEIVERS, true)) {
            $inherited = isset($classIndex[$callerClass])
                && ! isset($classIndex[$callerClass]['methods'][strtolower($calleeName)]);

            return [$callerClass, $inherited];
        }

        // parent::m() -> the parent class (must be known).
        if ($token === 'parent') {
            $parent = $classIndex[$callerClass]['parent'] ?? null;
            if ($parent === null || $parent === '') {
                $stats['skipped_unresolved_parent']++;

                return null;
            }

            return [$parent, true];
        }

        // "unknown" or any un-typed / dynamic receiver -> not type-resolvable.
        $stats['skipped_dynamic']++;

        return null;
    }

    /**
     * Resolve an explicit class Name to a FQN: prefer an exact import alias for the file,
     * otherwise treat it as same-namespace (callerClass namespace + '\\' + Name). A Name that
     * is already fully-qualified (contains a backslash) is taken as-is (normalized).
     *
     * @param  array<string,array<string,string>>  $importsByFile
     * @param  array<string,array{parent:?string, methods:array<string,bool>, namespace:string}>  $classIndex
     */
    private function resolveClassName(string $name, string $path, string $callerClass, array $importsByFile, array $classIndex): ?string
    {
        $clean = ltrim(trim($name), '\\');
        if ($clean === '') {
            return null;
        }

        // 1) import alias for this file (alias matched on the leading segment).
        $alias = $clean;
        $tail = '';
        $slash = strpos($clean, '\\');
        if ($slash !== false) {
            $alias = substr($clean, 0, $slash);
            $tail = substr($clean, $slash); // includes leading backslash
        }
        $imported = $importsByFile[$path][$alias] ?? null;
        if ($imported !== null) {
            return ltrim($imported, '\\').$tail;
        }

        // 2) already fully-qualified (multi-segment, no alias hit) -> take as-is.
        if ($slash !== false) {
            return $clean;
        }

        // 3) same namespace as the caller.
        $ns = $classIndex[$callerClass]['namespace'] ?? '';
        if ($ns !== '') {
            return $ns.'\\'.$clean;
        }

        // 4) global namespace.
        return $clean;
    }

    /**
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,int>  $stats
     */
    private function addEdge(array &$edges, array &$stats, string $from, string $to, string $calleeName, string $receiverLabel, bool $inherited, string $sourceRef): void
    {
        $key = $from.'|'.$to.'|'.self::EDGE_CALLS;
        if (isset($edges[$key])) {
            $stats['deduped']++;
            $edges[$key]['metadata']['occurrences']++;

            return;
        }

        $stats['edges']++;
        $stats['extracted']++;

        $metadata = [
            'resolver' => self::SCHEMA,
            'relation' => 'call',
            'callee_name' => $calleeName,
            'receiver' => $receiverLabel,
            'confidence' => self::CONFIDENCE_EXTRACTED,
            'confidence_score' => self::SCORE_EXTRACTED,
            'source_ref' => $sourceRef,
            'occurrences' => 1,
            'inferred' => false,
        ];
        if ($inherited) {
            $metadata['inherited'] = true;
        }

        $edges[$key] = [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => self::EDGE_CALLS,
            'confidence' => self::CONFIDENCE_EXTRACTED,
            'confidence_score' => self::SCORE_EXTRACTED,
            'metadata' => $metadata,
        ];
    }

    /**
     * The {static_class: Name} class name, or null when the receiver is not a static-class record.
     */
    private function staticClassName(mixed $receiver): ?string
    {
        if (is_array($receiver) && array_key_exists('static_class', $receiver)) {
            return $this->str($receiver['static_class']);
        }
        // {var_type: Name} ($var->m() with a known type from `new X()` or a param
        // hint) resolves IDENTICALLY to an explicit class reference: resolve Name
        // via imports/namespace, existence-gated, EXTRACTED.
        if (is_array($receiver) && array_key_exists('var_type', $receiver)) {
            return $this->str($receiver['var_type']);
        }

        return null;
    }

    /**
     * Human-readable receiver tag for edge metadata. For an explicit class reference we record
     * the RESOLVED target class FQN (the precise, type-aware value), not the raw alias.
     */
    private function receiverLabel(mixed $receiver, string $resolvedTargetClass): string
    {
        if (is_array($receiver) && array_key_exists('var_type', $receiver)) {
            return 'var_type:'.$resolvedTargetClass;
        }
        if ($this->staticClassName($receiver) !== null) {
            return 'static_class:'.$resolvedTargetClass;
        }
        if (is_string($receiver)) {
            $t = strtolower(trim($receiver));

            return $t === '' ? 'unknown' : $t;
        }

        return 'unknown';
    }

    /**
     * Method short-name key from a FQN (the part after '::'), lowercased for case-insensitive
     * declaration lookup. Used only for the inherited determination.
     */
    private function methodKeyFromFqn(string $fqn): string
    {
        $pos = strrpos($fqn, '::');

        return strtolower($pos === false ? $fqn : substr($fqn, $pos + 2));
    }

    /**
     * Normalize the class index: FQN keys de-backslashed; parent normalized to ?FQN; methods
     * flattened to a lowercased short-name set for O(1) declaration lookup; namespace kept as
     * a de-backslashed string. Defensive against a dirty read-model.
     *
     * @param  array<string,array{parent?:?string, methods?:array<int,string>, namespace?:string}>  $classIndex
     * @return array<string,array{parent:?string, methods:array<string,bool>, namespace:string}>
     */
    private function normalizeClassIndex(array $classIndex): array
    {
        $out = [];
        foreach ($classIndex as $fqn => $meta) {
            $name = $this->fqn($fqn);
            if ($name === null || ! is_array($meta)) {
                continue;
            }
            $parent = $this->fqn($meta['parent'] ?? null);

            $methods = [];
            $rawMethods = $meta['methods'] ?? [];
            if (is_array($rawMethods)) {
                foreach ($rawMethods as $m) {
                    $ms = $this->str($m);
                    if ($ms !== null) {
                        // store both the bare short name and the tail after '::' if a FQN slipped in
                        $methods[strtolower($ms)] = true;
                        $methods[$this->methodKeyFromFqn($ms)] = true;
                    }
                }
            }

            $ns = $this->str($meta['namespace'] ?? null);
            $ns = $ns === null ? '' : ltrim($ns, '\\');

            $out[$name] = [
                'parent' => $parent,
                'methods' => $methods,
                'namespace' => $ns,
            ];
        }

        return $out;
    }

    /**
     * Normalize the per-file import map: keep only string path -> {string alias => string FQN}.
     *
     * @param  array<string,array<string,string>>  $importsByFile
     * @return array<string,array<string,string>>
     */
    private function normalizeImports(array $importsByFile): array
    {
        $out = [];
        foreach ($importsByFile as $path => $aliases) {
            if (! is_string($path) || ! is_array($aliases)) {
                continue;
            }
            $map = [];
            foreach ($aliases as $alias => $fqn) {
                if (! is_string($alias)) {
                    continue;
                }
                $a = trim($alias);
                $f = $this->fqn($fqn);
                if ($a !== '' && $f !== null) {
                    $map[$a] = $f;
                }
            }
            if ($map !== []) {
                $out[$path] = $map;
            }
        }

        return $out;
    }

    /**
     * Deterministic node id for a method FQN, kept within the world-model node_id varchar(160)
     * limit. Identical scheme to {@see CodeGraphSymbolResolver::nodeId()} and
     * {@see CodeGraphCallResolver::nodeId()} so a method node here collides with the same node
     * produced by the structural/heuristic resolvers.
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

        // Normalize: drop a leading backslash; keep namespace + Class[::method].
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
