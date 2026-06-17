<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE DC7 — de-orphan {@see AtlasLoopHeavyWorkSelector} into the live refiller. The selector ranks heavy
 * candidates by "the biggest PROVEN leap" (deterministic panel value · scope, gated by a Bayesian per-class
 * accept rate), but had NO production consumer: the loop's obra-cluster producer detected leverage-ranked
 * candidates and merely COUNTED them. This ranker feeds the REAL detected payloads (their measured
 * leverage_signals — cyclomatic, refactor_leverage, caller_count — NOT a number any agent emitted) plus REAL
 * per-class accept stats from the decomposition-outcomes ledger into the selector, and returns the pick + the
 * ranked order so the operator's obra review surfaces the highest-value-proven cluster first.
 *
 * No new table, no provider call, no mutation — a pure read-rank over already-parked candidates. Fail-OPEN
 * (empty/unmappable input or a DB hiccup => empty ranking). The caller gates it default-OFF, so OFF adds no
 * ranking field and the refill envelope is byte-identical.
 */
final class AtlasLoopObraCandidateRanker
{
    public function __construct(private readonly ?AtlasLoopHeavyWorkSelector $selector = null) {}

    /**
     * Rank loop-detected obra candidate payloads (each a {@see AtlasLoopObraClusterCandidate::toBacklogProposalPayload}).
     *
     * @param  list<array<string,mixed>>  $payloads
     * @return array{pick:?string, ranked:list<array{candidateId:string, class:string, gate:string}>}
     */
    public function rank(array $payloads): array
    {
        $candidates = [];
        foreach ($payloads as $payload) {
            $candidate = $this->toCandidate(is_array($payload) ? $payload : []);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            return ['pick' => null, 'ranked' => []];
        }

        try {
            $selector = $this->selector ?? new AtlasLoopHeavyWorkSelector;
            $result = $selector->select($candidates, ['class_stats' => $this->classStats()]);
        } catch (Throwable) {
            return ['pick' => null, 'ranked' => []];
        }

        $ranked = [];
        foreach ((array) ($result['ranked'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ranked[] = [
                'candidateId' => (string) ($row['candidateId'] ?? ''),
                'class' => (string) ($row['class'] ?? ''),
                'gate' => (string) ($row['gate'] ?? ''),
            ];
        }

        $pick = $result['pick'] ?? null;

        return [
            'pick' => is_array($pick) && isset($pick['candidateId']) ? (string) $pick['candidateId'] : null,
            'ranked' => $ranked,
        ];
    }

    /**
     * Map one parked obra-candidate payload to the selector's candidate shape, using ONLY the measured
     * leverage signals (never an agent-emitted number). Null when the payload carries no candidate block.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function toCandidate(array $payload): ?array
    {
        $c = $payload['obra_cluster_candidate'] ?? null;
        if (! is_array($c)) {
            return null;
        }
        $id = trim((string) ($c['cluster_hash'] ?? ''));
        if ($id === '') {
            return null;
        }
        $files = array_values((array) ($c['allowed_files'] ?? []));
        $lev = (array) ($c['leverage_signals'] ?? []);
        $kind = trim((string) ($c['objective_kind'] ?? '')) ?: 'obra_candidate';

        return [
            'candidateId' => $id,
            'kind' => $kind,
            'class' => $kind,
            'node_count' => max(1, count($files)),
            'allowed_files' => $files,
            'evidence' => [
                'refactor_leverage' => (float) ($lev['refactor_leverage'] ?? 0.0),
                'cyclomatic_total' => (int) ($lev['cyclomatic_total'] ?? 0),
                'blast_radius' => (int) ($lev['caller_count'] ?? max(0, count($files) - 1)),
            ],
        ];
    }

    /**
     * Per-class {successes, failures} from the decomposition-outcomes ledger (DC4 store) — the SAME machine-
     * resolved certified/thrashed terminal outcomes, keyed by objective_kind to match the candidate class.
     * Fail-OPEN empty (the selector then leans on a neutral Laplace prior, never a fabricated accept rate).
     *
     * @return array<string, array{successes:int, failures:int}>
     */
    private function classStats(): array
    {
        if (! DatabaseTableAvailability::all(['atlas_loop_decomposition_outcomes'])) {
            return [];
        }
        try {
            $rows = DB::table('atlas_loop_decomposition_outcomes')
                ->whereNotNull('objective_kind')
                ->limit(8000)
                ->get(['objective_kind', 'certified']);
        } catch (Throwable) {
            return [];
        }

        $stats = [];
        foreach ($rows as $r) {
            $k = trim((string) ($r->objective_kind ?? ''));
            if ($k === '') {
                continue;
            }
            $stats[$k] ??= ['successes' => 0, 'failures' => 0];
            if ((bool) $r->certified) {
                $stats[$k]['successes']++;
            } else {
                $stats[$k]['failures']++;
            }
        }

        return $stats;
    }
}
