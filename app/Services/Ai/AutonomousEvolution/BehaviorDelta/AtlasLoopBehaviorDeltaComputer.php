<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\BehaviorDelta;

/**
 * BEHAVIOR-Δ COMPUTER — diffs two {@see AtlasLoopBehaviorDeltaSnapshotter} snapshots into a TYPED behavior
 * delta. Pure + deterministic: same (before, after) ⇒ byte-identical output.
 *
 * `net_behavior_delta` is a COUNT of REAL structural changes (symbols added/removed, public-API signatures
 * changed, caller edges added/removed) — NEVER an LLM score. It is the honest replacement for the fan-in proxy
 * that AtlasLoopUtilityGradeService confesses to use: a number you can re-derive from two commits, not a model
 * opinion. Identical snapshots ⇒ 0. NEW class only — the operator wires it into the pétreo grader later.
 */
final class AtlasLoopBehaviorDeltaComputer
{
    public const SCHEMA = 'atlas.loop.behavior_delta.v1';

    private const EDGE_SEP = "\x1f";

    /**
     * @param  array<string,mixed>  $before  a behavior-delta snapshot
     * @param  array<string,mixed>  $after   a behavior-delta snapshot
     * @return array{schema:string, symbols_added:list<string>, symbols_removed:list<string>, api_signature_changed:list<array{fqcn:string, before_hash:string, after_hash:string}>, caller_edges_added:int, caller_edges_removed:int, net_behavior_delta:int}
     */
    public function compute(array $before, array $after): array
    {
        $beforeSymbols = $this->indexByFqcn($before);
        $afterSymbols = $this->indexByFqcn($after);

        $beforeFqcns = array_keys($beforeSymbols);
        $afterFqcns = array_keys($afterSymbols);

        $added = array_values(array_diff($afterFqcns, $beforeFqcns));
        $removed = array_values(array_diff($beforeFqcns, $afterFqcns));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);

        $changed = [];
        $common = array_intersect($beforeFqcns, $afterFqcns);
        sort($common, SORT_STRING);
        foreach ($common as $fqcn) {
            $beforeHash = (string) $beforeSymbols[$fqcn]['sig'];
            $afterHash = (string) $afterSymbols[$fqcn]['sig'];
            if ($beforeHash !== $afterHash) {
                $changed[] = ['fqcn' => $fqcn, 'before_hash' => $beforeHash, 'after_hash' => $afterHash];
            }
        }

        $beforeEdges = $this->edgeSet($beforeSymbols);
        $afterEdges = $this->edgeSet($afterSymbols);
        $callerEdgesAdded = count(array_diff($afterEdges, $beforeEdges));
        $callerEdgesRemoved = count(array_diff($beforeEdges, $afterEdges));

        $net = count($added) + count($removed) + count($changed) + $callerEdgesAdded + $callerEdgesRemoved;

        return [
            'schema' => self::SCHEMA,
            'symbols_added' => $added,
            'symbols_removed' => $removed,
            'api_signature_changed' => $changed,
            'caller_edges_added' => $callerEdgesAdded,
            'caller_edges_removed' => $callerEdgesRemoved,
            'net_behavior_delta' => $net,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,array{sig:string, callers:list<string>}>
     */
    private function indexByFqcn(array $snapshot): array
    {
        $index = [];
        foreach ((array) ($snapshot['symbols'] ?? []) as $symbol) {
            if (! is_array($symbol)) {
                continue;
            }
            $fqcn = ltrim((string) ($symbol['fqcn'] ?? ''), '\\');
            if ($fqcn === '') {
                continue;
            }
            $callers = array_values(array_filter((array) ($symbol['caller_fqcns'] ?? []), 'is_string'));

            $index[$fqcn] = [
                'sig' => (string) ($symbol['public_api_signature_hash'] ?? ''),
                'callers' => $callers,
            ];
        }

        return $index;
    }

    /**
     * The set of directed caller edges `symbolFqcn -> callerFqcn` across the whole snapshot.
     *
     * @param  array<string,array{sig:string, callers:list<string>}>  $symbols
     * @return list<string>
     */
    private function edgeSet(array $symbols): array
    {
        $edges = [];
        foreach ($symbols as $fqcn => $data) {
            foreach ($data['callers'] as $caller) {
                $edges[$fqcn.self::EDGE_SEP.$caller] = true;
            }
        }

        return array_keys($edges);
    }
}
