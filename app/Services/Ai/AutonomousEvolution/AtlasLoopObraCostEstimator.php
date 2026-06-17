<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkClassPriorService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever M4 — the budget scheduler's missing COST SOURCE (de-orphan the economics brain).
 *
 * {@see AtlasLoopBudgetScheduler} is a pure value/cost knapsack that needs each obra's per-provider estimated
 * cost; with `costs` empty every obra is unschedulable and deferred. The cost was never wired. M4 fits it
 * DETERMINISTICALLY from frozen attempt telemetry: the explorations ledger already records per attempt the
 * `provider`, `cost_estimate_usd`, and `tokens_used`, so the mean real spend per provider for a work-class is a
 * measured number — never a guess. {@see attachCosts} fills each obra's `costs` map from the telemetry of its
 * target's work-class, producing scheduler-ready input.
 *
 * Provider-safe + fail-OPEN: a missing table / query error / no telemetry yields an EMPTY cost map (the obra
 * stays honestly deferred, never scheduled on a fabricated cost). The pure {@see aggregate} core takes raw rows
 * so it is unit-testable without a database. NOTE: this de-orphans the cost INPUT; the scheduler's live
 * concurrent dispatch is a separate integration (the scheduler has no production caller yet).
 */
final class AtlasLoopObraCostEstimator
{
    public function __construct(private readonly ?AtlasLoopWorkClassPriorService $workClass = null) {}

    /**
     * Fill each obra's per-provider `costs` map from the telemetry of its `target_path` work-class. An obra with
     * no derivable target / no telemetry keeps whatever `costs` it already had (possibly empty => deferred).
     *
     * @param  list<array<string,mixed>>  $obras  each: {id, value?, target_path?, costs?}
     * @return list<array<string,mixed>>
     */
    public function attachCosts(array $obras, ?int $hours = null): array
    {
        $out = [];
        foreach ($obras as $obra) {
            if (! is_array($obra)) {
                continue;
            }
            $target = trim((string) ($obra['target_path'] ?? ''));
            if ($target !== '' && (! isset($obra['costs']) || ! is_array($obra['costs']) || $obra['costs'] === [])) {
                $costs = $this->costsForTarget($target, $hours);
                if ($costs !== []) {
                    $obra['costs'] = $costs;
                }
            }
            $out[] = $obra;
        }

        return $out;
    }

    /**
     * The mean measured cost per provider for the work-class of $targetPath, over a recent telemetry window.
     * Fail-open empty.
     *
     * @return array<string,float>
     */
    public function costsForTarget(string $targetPath, ?int $hours = null): array
    {
        $window = max(1, min(2160, (int) ($hours ?? config('atlas.loop.obra_cost_window_hours', 336))));
        $workClass = ($this->workClass ?? new AtlasLoopWorkClassPriorService)->workClass($targetPath);

        if (! DatabaseTableAvailability::all(['atlas_loop_explorations', 'atlas_loop_tasks'])) {
            return [];
        }

        try {
            $rows = DB::table('atlas_loop_explorations as e')
                ->join('atlas_loop_tasks as t', 't.id', '=', 'e.task_id')
                ->where('e.updated_at', '>=', Carbon::now()->subHours($window))
                ->limit(4000)
                ->get(['t.target_path', 'e.attempt_metrics'])
                ->map(static fn ($r): array => ['target_path' => (string) ($r->target_path ?? ''), 'attempt_metrics' => $r->attempt_metrics ?? null])
                ->all();
        } catch (Throwable) {
            return [];
        }

        // Keep only rows whose target shares the queried work-class, then aggregate per provider.
        $wc = $this->workClass ?? new AtlasLoopWorkClassPriorService;
        $scoped = array_values(array_filter($rows, static fn (array $row): bool => $wc->workClass((string) $row['target_path']) === $workClass));

        return $this->aggregate($scoped);
    }

    /**
     * PURE — mean real spend per provider from raw explorations rows. Prefers cost_estimate_usd; falls back to
     * tokens_used when no USD cost is present. Only provider-invoked attempts with a positive cost count.
     *
     * @param  list<array{target_path?:string, attempt_metrics:mixed}>  $rows
     * @return array<string,float>
     */
    public function aggregate(array $rows): array
    {
        /** @var array<string,array{sum:float,n:int}> $byProvider */
        $byProvider = [];
        foreach ($rows as $row) {
            foreach ($this->arrayPayload($row['attempt_metrics'] ?? null) as $attempt) {
                if (! is_array($attempt) || ($attempt['provider_invoked'] ?? null) !== true) {
                    continue;
                }
                $provider = trim((string) ($attempt['provider'] ?? ''));
                if ($provider === '') {
                    continue;
                }
                $usd = is_numeric($attempt['cost_estimate_usd'] ?? null) ? (float) $attempt['cost_estimate_usd'] : 0.0;
                $tokens = is_numeric($attempt['tokens_used'] ?? null) ? (float) $attempt['tokens_used'] : 0.0;
                $cost = $usd > 0.0 ? $usd : $tokens;
                if (! is_finite($cost) || $cost <= 0.0) {
                    continue;
                }
                $byProvider[$provider] ??= ['sum' => 0.0, 'n' => 0];
                $byProvider[$provider]['sum'] += $cost;
                $byProvider[$provider]['n']++;
            }
        }

        $out = [];
        foreach ($byProvider as $provider => $agg) {
            if ($agg['n'] > 0) {
                $out[$provider] = round($agg['sum'] / $agg['n'], 6);
            }
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function arrayPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values($payload);
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
