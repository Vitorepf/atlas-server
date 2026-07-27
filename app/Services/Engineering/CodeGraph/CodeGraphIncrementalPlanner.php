<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Incremental re-index planner for the code graph (AP-811 / AP-812, P-12).
 *
 * Given the content hashes from the *previous* index and the *current* index,
 * this computes the minimal work queue — the make(1)-style "what changed?"
 * diff — so a re-index only re-parses files whose content actually moved
 * instead of rebuilding the whole graph every run.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime. It consumes two
 * already-computed `path => content_hash` maps and returns a deterministic plan.
 * Storage, hashing of files on disk, and the actual re-parse live in adapters.
 *
 * Semantics (content-hash diff):
 *  - changed  = paths whose hash differs from the previous run, PLUS paths that
 *               are new (present now, absent before). These are the files that
 *               must be re-parsed.
 *  - removed  = paths present in the previous run but gone now. Their nodes/edges
 *               must be evicted from the graph.
 *  - unchanged_count = paths whose hash is byte-for-byte identical across runs.
 *
 * Determinism: both lists are sorted ascending by path (SORT_STRING), so the
 * same inputs always yield byte-identical output regardless of map ordering.
 */
class CodeGraphIncrementalPlanner
{
    public const SCHEMA = 'atlas.code_graph.incremental_plan.v1';

    /**
     * @param  array<string,mixed>  $previousHashes  path => content hash from the prior index
     * @param  array<string,mixed>  $currentHashes   path => content hash from the current scan
     * @return array{schema_version:string, changed:array<int,string>, removed:array<int,string>, unchanged_count:int}
     */
    public function plan(array $previousHashes, array $currentHashes): array
    {
        $previous = $this->normalize($previousHashes);
        $current = $this->normalize($currentHashes);

        $changed = [];
        $unchangedCount = 0;

        // Walk the current set: new paths and hash-mismatched paths need re-parse;
        // byte-identical hashes are counted as unchanged.
        foreach ($current as $path => $hash) {
            if (! array_key_exists($path, $previous)) {
                $changed[$path] = true;

                continue;
            }
            if ($previous[$path] !== $hash) {
                $changed[$path] = true;

                continue;
            }
            $unchangedCount++;
        }

        // Walk the previous set: paths that no longer exist were removed.
        $removed = [];
        foreach ($previous as $path => $hash) {
            if (! array_key_exists($path, $current)) {
                $removed[$path] = true;
            }
        }

        $changedList = array_keys($changed);
        $removedList = array_keys($removed);
        sort($changedList, SORT_STRING);
        sort($removedList, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'changed' => $changedList,
            'removed' => $removedList,
            'unchanged_count' => $unchangedCount,
        ];
    }

    /**
     * Coerce a `path => hash` map into a clean string-keyed/string-valued map.
     * Non-string paths are dropped; hashes are stringified so "1" === 1 never
     * masquerades as a change. Paths are trimmed; empty paths are dropped.
     *
     * @param  array<string,mixed>  $map
     * @return array<string,string>
     */
    private function normalize(array $map): array
    {
        $out = [];
        foreach ($map as $path => $hash) {
            if (! is_string($path)) {
                continue;
            }
            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }
            $out[$trimmed] = $this->stringifyHash($hash);
        }

        return $out;
    }

    private function stringifyHash(mixed $hash): string
    {
        if (is_string($hash)) {
            return $hash;
        }
        if (is_int($hash) || is_float($hash)) {
            return (string) $hash;
        }
        if (is_bool($hash)) {
            return $hash ? '1' : '0';
        }
        if ($hash === null) {
            return '';
        }

        // Arrays / objects: stable JSON so structurally-equal values compare equal.
        return (string) json_encode($hash);
    }
}
