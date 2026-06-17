<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE F7 — step reorder by historical FIRST-PASS rate (a compounding seed).
 *
 * An obra walks its nodes in seq order onto ONE accumulating branch and HALTS on the first node that does
 * not certify. So the ORDER of independent steps matters: doing the historically-easiest-first banks
 * certified progress before a hard step can halt the obra (maximizing partial delivery, and surfacing a
 * likely-hard step's failure feedback earlier). F7 reorders nodes WITHIN their dependency structure by a
 * per-shape first-pass certified rate learned from the existing `atlas_obra_nodes` ledger — NO new table.
 *
 * SAFETY: the reorder is a topological sort that NEVER places a node before a node it depends_on — it only
 * chooses, among the currently-ready nodes, the one with the higher first-pass rate (ties broken by original
 * seq, so the order is deterministic). This rides the SAME independence assumption the already-shipped wave
 * scheduler uses (declared depends_on captures ordering-sensitive steps). With an empty/equal rate map the
 * priority collapses to seq, so the result equals the original seq order — i.e. it is a no-op until the prior
 * fills (the compounding-seed property). The caller gates it default-OFF, so OFF is byte-identical regardless.
 */
final class AtlasLoopNodeFirstPassReorder
{
    /**
     * A coarse, stable shape key for a node so first-pass rates accumulate across obras: the leading verb of
     * the request (create/extract/redirect/…) plus the target file extension. Provider-safe (no content).
     *
     * @param  array<string,mixed>  $node
     */
    public function shapeKey(array $node): string
    {
        $request = mb_strtolower(trim((string) ($node['request'] ?? '')));
        $verb = $request === '' ? 'step' : (string) (preg_split('/\s+/', $request)[0] ?? 'step');
        $verb = preg_replace('/[^a-z]/', '', $verb) ?: 'step';
        $target = (string) ($node['target_area'] ?? '');
        $ext = '';
        if ($target !== '' && str_contains($target, '.')) {
            $ext = strtolower((string) (pathinfo($target, PATHINFO_EXTENSION) ?: ''));
        }

        return $ext === '' ? $verb : $verb.':'.$ext;
    }

    /**
     * Per-shape first-pass certified rate from the historical node ledger. A node "first-passed" when its
     * terminal status is 'done'; it did not when 'failed'. ('skipped'/'pending'/'running' are not terminal
     * first-pass evidence and are ignored.) Fail-OPEN: missing table / error => empty map => the reorder is a
     * no-op. The pure {@see aggregateRates} core is unit-testable without a DB.
     *
     * @return array<string,float>
     */
    public function ratesByShape(?int $hours = null): array
    {
        $window = max(1, min(8760, (int) ($hours ?? config('atlas.obra.first_pass_reorder_window_hours', 720))));
        if (! DatabaseTableAvailability::all(['atlas_obra_nodes'])) {
            return [];
        }
        try {
            $rows = DB::table('atlas_obra_nodes')
                ->whereIn('status', ['done', 'failed'])
                ->where('updated_at', '>=', Carbon::now()->subHours($window))
                ->limit(8000)
                ->get(['request', 'target_area', 'status'])
                ->map(fn ($r): array => [
                    'shape' => $this->shapeKey(['request' => $r->request ?? '', 'target_area' => $r->target_area ?? '']),
                    'status' => (string) $r->status,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }

        return $this->aggregateRates($rows);
    }

    /**
     * PURE: collapse raw {shape,status} rows into a per-shape first-pass rate (done / (done+failed)). A shape
     * with no terminal rows is absent (treated as unknown=>neutral by the reorder).
     *
     * @param  list<array{shape:string,status:string}>  $rows
     * @return array<string,float>
     */
    public function aggregateRates(array $rows): array
    {
        /** @var array<string,array{done:int,total:int}> $tally */
        $tally = [];
        foreach ($rows as $row) {
            $shape = (string) ($row['shape'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if ($shape === '' || ($status !== 'done' && $status !== 'failed')) {
                continue;
            }
            $tally[$shape] ??= ['done' => 0, 'total' => 0];
            $tally[$shape]['total']++;
            if ($status === 'done') {
                $tally[$shape]['done']++;
            }
        }

        $rates = [];
        foreach ($tally as $shape => $t) {
            $rates[$shape] = $t['total'] > 0 ? round($t['done'] / $t['total'], 4) : 0.0;
        }

        return $rates;
    }

    /**
     * DAG-SAFE reorder: a topological sort that, among the currently-ready nodes (all depends_on already
     * placed), picks the one with the HIGHER first-pass rate; ties break by original seq (deterministic). A
     * node is NEVER placed before a node it depends on. An unknown shape gets the neutral default so it
     * neither jumps ahead nor sinks. Returns the nodes reordered with `seq` reindexed to the new order. Only
     * depends_on edges that point at nodes IN this plan are honored (external/dangling deps are ignored so a
     * bad edge can never deadlock — the defensive fallback also drains any residual by seq).
     *
     * @param  list<array<string,mixed>>  $nodes
     * @param  array<string,float>  $rateByShape
     * @param  float  $unknownRate  neutral rate for a shape with no history (default 0.5)
     * @return list<array<string,mixed>>
     */
    public function reorder(array $nodes, array $rateByShape, float $unknownRate = 0.5): array
    {
        if (count($nodes) <= 1) {
            return array_values($nodes);
        }

        // Index by id; capture original seq for the stable tie-break.
        $byId = [];
        $idOrder = [];
        foreach ($nodes as $i => $n) {
            $id = (string) ($n['id'] ?? ('#'.$i));
            $byId[$id] = $n;
            $idOrder[$id] = (int) ($n['seq'] ?? $i);
        }
        $presentIds = array_fill_keys(array_keys($byId), true);

        $rateOf = function (array $n) use ($rateByShape, $unknownRate): float {
            $shape = $this->shapeKey($n);

            return array_key_exists($shape, $rateByShape) ? (float) $rateByShape[$shape] : $unknownRate;
        };

        $placed = [];
        $placedIds = [];
        $remaining = array_keys($byId);

        while ($remaining !== []) {
            // ready = every remaining node whose in-plan deps are all placed.
            $ready = [];
            foreach ($remaining as $id) {
                $deps = (array) ($byId[$id]['depends_on'] ?? []);
                $ok = true;
                foreach ($deps as $dep) {
                    $dep = (string) $dep;
                    if (isset($presentIds[$dep]) && ! isset($placedIds[$dep])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $ready[] = $id;
                }
            }
            if ($ready === []) {
                // Defensive: a cycle or unresolved edge — drain the rest in original seq order (never hang).
                usort($remaining, fn (string $a, string $b): int => $idOrder[$a] <=> $idOrder[$b]);
                foreach ($remaining as $id) {
                    $placed[] = $byId[$id];
                }
                break;
            }
            // Pick the highest first-pass rate; tie -> lowest original seq (stable, deterministic).
            usort($ready, function (string $a, string $b) use ($rateOf, $byId, $idOrder): int {
                $ra = $rateOf($byId[$a]);
                $rb = $rateOf($byId[$b]);
                if ($ra !== $rb) {
                    return $rb <=> $ra; // higher rate first
                }

                return $idOrder[$a] <=> $idOrder[$b];
            });
            $pick = $ready[0];
            $placed[] = $byId[$pick];
            $placedIds[$pick] = true;
            $remaining = array_values(array_filter($remaining, fn (string $id): bool => $id !== $pick));
        }

        // Reindex seq to the new order.
        $out = [];
        foreach (array_values($placed) as $i => $n) {
            $n['seq'] = $i;
            $out[] = $n;
        }

        return $out;
    }
}
