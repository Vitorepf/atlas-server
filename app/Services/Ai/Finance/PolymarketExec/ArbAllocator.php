<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use Illuminate\Support\Carbon;

/**
 * The capital allocator (greedy knapsack) across BOTH arb directions.
 *
 * The operator's principle, made structural: capture EVERY net-positive
 * opportunity — including cents — never pre-filtering the small ones. The only
 * floor is gas-aware net-positivity, which already lives in the gate
 * (min_net_edge_per_set). The allocator ranks the live opportunities by value,
 * then dispatches them one at a time under the daily budget, re-reading the
 * remaining budget after every basket so the short side — which mints, sells and
 * settles synchronously, freeing its slot and recycling cash immediately — lets
 * the same bankroll serve many baskets in one pass.
 *
 * Pure orchestration: it owns no venue access. The planners and state machines
 * are injected (sim or live, already wired), so the allocator is fully testable
 * and the same logic runs in shadow-sim and live.
 */
final class ArbAllocator
{
    public function __construct(
        private readonly PolyExecConfig $cfg,
        private readonly PolyExecGate $gate,
        private readonly BasketPlanner $longPlanner,
        private readonly ShortBasketPlanner $shortPlanner,
        private readonly BasketStateMachine $longMachine,
        private readonly MintSellStateMachine $shortMachine,
    ) {}

    /**
     * @param  list<array{event_slug: string, kind: string, legs: list<array{token: string, question?: string}>, persistence_seconds: int, rank_profit_usd?: float}>  $candidates
     * @return array{processed: int, dispatched: int, blocked: string|null, results: list<array<string, mixed>>}
     */
    public function allocate(string $mode, string $sessionId, array $candidates, ?int $maxDispatch = null): array
    {
        // Rank by value desc — the operator's "priorize as melhores" — but the small
        // ones are NOT dropped; they are simply served after the larger ones while
        // budget remains.
        usort($candidates, fn (array $a, array $b) => ($b['rank_profit_usd'] ?? 0.0) <=> ($a['rank_profit_usd'] ?? 0.0));

        $cap = $maxDispatch ?? max(1, (int) ceil($this->cfg->dailyCapUsd / 0.05)); // budget is the real limiter
        $results = [];
        $processed = 0;
        $dispatched = 0;
        $blocked = null;

        foreach ($candidates as $c) {
            if (count($results) >= $cap) {
                break;
            }

            // Runtime caps gate EVERY dispatch: kill-switch, live flag, daily halt,
            // remaining budget, and concurrency vs any other in-flight baskets.
            $runtime = $this->gate->checkRuntimeCaps($mode);
            if (! $runtime->allowed) {
                $blocked = $runtime->blockingReasons();
                break; // kill / halt / budget exhausted: stop the whole pass
            }

            $remaining = max(0.0, $this->cfg->dailyCapUsd - $this->gate->deployedToday($mode));
            if ($remaining <= 0.0) {
                $blocked = 'daily_budget_exhausted';
                break;
            }

            $kind = (string) $c['kind'];
            $slug = (string) $c['event_slug'];
            $persist = (int) ($c['persistence_seconds'] ?? 0);
            $basketId = $this->basketId($slug, $kind, $mode);
            $processed++;

            if ($kind === 'long_sum_under') {
                $plan = $this->longPlanner->plan($slug, $kind, $c['legs'], $persist, $remaining);
                if (! $plan instanceof BasketPlan) {
                    $results[] = ['event_slug' => $slug, 'kind' => $kind, 'status' => 'unplannable'];

                    continue;
                }
                $dispatched++;
                $results[] = $this->longMachine->execute($plan, $basketId, $sessionId);
            } elseif ($kind === 'short_sum_over') {
                if (! $this->cfg->shortEnabled) {
                    $results[] = ['event_slug' => $slug, 'kind' => $kind, 'status' => 'short_disabled'];

                    continue;
                }
                $plan = $this->shortPlanner->plan($slug, $c['legs'], $persist, $remaining);
                if (! $plan instanceof ShortBasketPlan) {
                    $results[] = ['event_slug' => $slug, 'kind' => $kind, 'status' => 'unplannable'];

                    continue;
                }
                $dispatched++;
                $results[] = $this->shortMachine->execute($plan, $basketId, $sessionId);
            } else {
                $results[] = ['event_slug' => $slug, 'kind' => $kind, 'status' => 'unknown_kind'];
            }
        }

        return ['processed' => $processed, 'dispatched' => $dispatched, 'blocked' => $blocked, 'results' => $results];
    }

    /** Deterministic id: at most one basket per (event, kind, mode, day) — idempotent. */
    private function basketId(string $eventSlug, string $kind, string $mode): string
    {
        $prefix = $kind === 'short_sum_over' ? 'exs' : 'exl';

        return $prefix.'-'.substr(hash('sha256', $eventSlug.'|'.$kind.'|'.$mode.'|'.Carbon::now()->toDateString()), 0, 28);
    }
}
