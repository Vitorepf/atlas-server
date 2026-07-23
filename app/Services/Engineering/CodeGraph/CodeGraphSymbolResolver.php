<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Symbol-level keystone for the code graph (AP-811, granularity upgrade).
 *
 * Where {@see CodeGraphEdgeResolver} resolves module->module edges, this resolves
 * SYMBOL->SYMBOL edges: the classes defined in a referencing file -> the actual
 * class/symbol they import or reference, keyed by fully-qualified name. Node ids
 * are "sym:<FQN>". This is the fine-grained graph the module graph approximates.
 *
 * Pure transform: no DB, no IO. The caller supplies the already-loaded symbols
 * and flattened relations from the Code Intelligence read-model.
 *
 * Keeper techniques (same as the module resolver, applied per-symbol):
 *  - existence + single-candidate gate: only emit an edge to a FQN that resolves
 *    to exactly one known defining symbol — never invent a target;
 *  - import-evidence promotion: a reference becomes EXTRACTED when the file also
 *    imports the symbol, otherwise INFERRED;
 *  - dedup with strongest-evidence-wins + occurrence counting.
 */
class CodeGraphSymbolResolver
{
    public const SCHEMA = 'atlas.code_graph.symbol_edges.v1';

    public const CONFIDENCE_EXTRACTED = 'EXTRACTED';
    public const CONFIDENCE_INFERRED = 'INFERRED';

    private const EDGE_DEPENDS_ON = 'depends_on';
    private const EDGE_TESTS = 'tests';

    private const SCORE_EXTRACTED = 1.0;
    private const SCORE_REFERENCE_INFERRED = 0.85;
    private const SCORE_TEST_INFERRED = 0.8;

    /** Symbol kinds that are real graph nodes (the code backbone). */
    private const NODE_KINDS = ['class', 'interface', 'trait', 'enum', 'method'];

    /**
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @param  array<int,array{file_path:string,symbol:string,kind:string}>  $relations  flattened code-intel relations
     * @return array{schema_version:string, symbol_node_ids:array<int,string>, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function resolve(array $symbols, array $relations): array
    {
        // Index: FQN -> defining node id (single-candidate; a FQN defined in two
        // different files is AMBIGUOUS and is never used as an edge target).
        $defByFqn = [];
        $defFileByFqn = [];
        $collisions = [];
        $classesByFile = [];
        foreach ($symbols as $sym) {
            if (! is_array($sym)) {
                continue;
            }
            $name = $this->fqn($sym['name'] ?? null);
            $type = is_string($sym['type'] ?? null) ? strtolower((string) $sym['type']) : '';
            $file = $this->str($sym['file_path'] ?? null);
            if ($name === null || ! in_array($type, self::NODE_KINDS, true)) {
                continue;
            }
            $defFile = $file ?? '';
            if (isset($defFileByFqn[$name]) && $defFileByFqn[$name] !== $defFile) {
                $collisions[$name] = true; // same FQN in a different file
            } else {
                $defFileByFqn[$name] = $defFile;
                $defByFqn[$name] = $this->nodeId($name);
            }
            // Track the class-like symbols defined per file (the edge sources).
            if ($file !== null && $type !== 'method') {
                $classesByFile[$file][] = $name;
            }
        }

        $edges = [];
        $usedNodes = [];
        $stats = ['relations' => 0, 'extracted' => 0, 'inferred' => 0, 'skipped_unresolved' => 0, 'skipped_self' => 0, 'deduped' => 0, 'ambiguous_target' => 0];

        // Per-file imported FQNs (for import-evidence promotion).
        $importedByFile = [];
        foreach ($relations as $rel) {
            if (is_array($rel) && ($this->kindFamily($rel['kind'] ?? '') === 'dependency')) {
                $f = $this->str($rel['file_path'] ?? null);
                $s = $this->fqn($rel['symbol'] ?? null);
                if ($f !== null && $s !== null) {
                    $importedByFile[$f][$s] = true;
                }
            }
        }

        foreach ($relations as $rel) {
            if (! is_array($rel)) {
                continue;
            }
            $stats['relations']++;
            $file = $this->str($rel['file_path'] ?? null);
            $targetFqn = $this->fqn($rel['symbol'] ?? null);
            $family = $this->kindFamily($rel['kind'] ?? '');
            if ($file === null || $targetFqn === null) {
                $stats['skipped_unresolved']++;

                continue;
            }
            if (isset($collisions[$targetFqn])) {
                $stats['ambiguous_target']++;

                continue; // not single-candidate
            }
            $targetNode = $defByFqn[$targetFqn] ?? null;
            $sources = $classesByFile[$file] ?? [];
            if ($targetNode === null || $sources === []) {
                $stats['skipped_unresolved']++;

                continue;
            }

            [$edgeType, $confidence, $score] = $this->grade($family, $file, $targetFqn, $importedByFile);

            foreach ($sources as $sourceFqn) {
                $sourceNode = $this->nodeId($sourceFqn);
                if ($sourceNode === $targetNode) {
                    $stats['skipped_self']++;

                    continue;
                }
                $this->addEdge($edges, $usedNodes, $stats, $sourceNode, $targetNode, $edgeType, $confidence, $score, (string) $rel['kind'], $file);
            }
        }

        $edgeList = array_values($edges);
        usort($edgeList, static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']] <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']]);

        return [
            'schema_version' => self::SCHEMA,
            'symbol_node_ids' => array_values(array_keys($usedNodes)),
            'edges' => $edgeList,
            'stats' => $stats,
        ];
    }

    /**
     * @return array{0:string,1:string,2:float}  [edge_type, confidence, score]
     * @param  array<string,array<string,bool>>  $importedByFile
     */
    private function grade(string $family, string $file, string $targetFqn, array $importedByFile): array
    {
        if ($family === 'dependency') {
            return [self::EDGE_DEPENDS_ON, self::CONFIDENCE_EXTRACTED, self::SCORE_EXTRACTED];
        }
        if ($family === 'test') {
            $promoted = isset($importedByFile[$file][$targetFqn]);

            return [self::EDGE_TESTS, $promoted ? self::CONFIDENCE_EXTRACTED : self::CONFIDENCE_INFERRED, $promoted ? self::SCORE_EXTRACTED : self::SCORE_TEST_INFERRED];
        }
        // reference: import-evidence promotion
        $promoted = isset($importedByFile[$file][$targetFqn]);

        return [self::EDGE_DEPENDS_ON, $promoted ? self::CONFIDENCE_EXTRACTED : self::CONFIDENCE_INFERRED, $promoted ? self::SCORE_EXTRACTED : self::SCORE_REFERENCE_INFERRED];
    }

    /**
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,bool>  $usedNodes
     * @param  array<string,int>  $stats
     */
    private function addEdge(array &$edges, array &$usedNodes, array &$stats, string $from, string $to, string $type, string $confidence, float $score, string $kind, string $sourceRef): void
    {
        $key = $from.'|'.$to.'|'.$type;
        if (isset($edges[$key])) {
            $stats['deduped']++;
            $edges[$key]['metadata']['occurrences']++;
            if ($score > $edges[$key]['confidence_score']) {
                $edges[$key]['confidence'] = $confidence;
                $edges[$key]['confidence_score'] = $score;
                $edges[$key]['metadata']['relation'] = $kind;
            }

            return;
        }

        $confidence === self::CONFIDENCE_EXTRACTED ? $stats['extracted']++ : $stats['inferred']++;
        $usedNodes[$from] = true;
        $usedNodes[$to] = true;

        $edges[$key] = [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'confidence' => $confidence,
            'confidence_score' => $score,
            'metadata' => [
                'resolver' => self::SCHEMA,
                'relation' => $kind,
                'confidence' => $confidence,
                'confidence_score' => $score,
                'source_ref' => $sourceRef,
                'occurrences' => 1,
                'inferred' => $confidence !== self::CONFIDENCE_EXTRACTED,
            ],
        ];
    }

    private function kindFamily(mixed $kind): string
    {
        $k = is_string($kind) ? strtolower($kind) : '';
        if (str_contains($k, 'use') || str_contains($k, 'import') || str_contains($k, 'depend')) {
            return 'dependency';
        }
        if (str_contains($k, 'test')) {
            return 'test';
        }

        return 'reference';
    }

    /**
     * Deterministic node id for a symbol FQN, kept within the world-model
     * node_id varchar(160) limit. Long FQNs keep their readable tail (the class
     * name) plus a stable hash so they remain unique and collision-safe.
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
