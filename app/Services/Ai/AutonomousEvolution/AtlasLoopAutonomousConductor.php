<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ABSURD-LEAP 1 — the AUTONOMOUS CONDUCTOR (the integration capstone that ARMS + chains the whole stack).
 *
 * The loop accumulated ~15 keystones (intent→spec compiler, decomposition planner, cross-provider best-of-N,
 * iterate-to-green, escalation ladder, multi-judge consensus, completeness gate, working-memory ledger) but
 * most ran in isolation. This conductor is the brain that makes them work TOGETHER as one autonomous
 * engineer: it drives a goal to CERTIFICATION through the escalating tiers, feeding the per-obra working
 * memory forward each round and stopping ONLY on certification (the quality bar) or the round budget — never
 * a fixed N. The escalation ladder decides the next tier; the conductor runs that tier's executor, records
 * the outcome into the ledger (so the next round is smarter), and detects thrashing to jump forward.
 *
 * Pure orchestration over INJECTED executors (each tier — best_of_n / repair_from_refutation / decompose /
 * escalate_provider — is a callable that returns {certified, reason, ...}), plus optional pre (compile_spec)
 * and post (merge) hooks. So the conductor brain is deterministically testable WITHOUT any provider; the
 * real tier executors (explorer / iterate-to-green / planner / portfolio) wire on top.
 */
final class AtlasLoopAutonomousConductor
{
    public function __construct(
        private readonly ?AtlasLoopEscalationLadder $ladder = null,
        private readonly ?AtlasLoopRejectionDimensionRouter $rejectionRouter = null,
    ) {}

    /**
     * @param  array{
     *     compile_spec?: callable(string): array<string,mixed>,
     *     tier_executors: array<string, callable>,
     *     merge?: callable(array<string,mixed>): array<string,mixed>,
     *     max_rounds?: int,
     *     thrash_threshold?: int
     * }  $opts
     * @return array{certified:bool, merged:bool, rounds:int, tier_history:list<array<string,mixed>>, final_reason:string, spec:?array<string,mixed>, convergence:array<string,mixed>, last_outcome:?array<string,mixed>}
     */
    public function conduct(string $goal, array $opts): array
    {
        $ladder = $this->ladder ?? new AtlasLoopEscalationLadder;
        $ledger = new AtlasLoopAttemptLedger;
        $tierExecutors = is_array($opts['tier_executors'] ?? null) ? $opts['tier_executors'] : [];
        $maxRounds = max(1, (int) ($opts['max_rounds'] ?? config('atlas.loop.escalation_max_rounds', 6)));
        $thrashThreshold = max(2, (int) ($opts['thrash_threshold'] ?? config('atlas.loop.escalation_thrash_threshold', 3)));

        // PRE: compile the natural-language goal into a structured spec (autonomy entry). Optional.
        $spec = null;
        if (is_callable($opts['compile_spec'] ?? null)) {
            $spec = ($opts['compile_spec'])($goal);
        }

        $history = [];
        $lastOutcome = null;
        $state = ['certified' => false, 'round' => 0, 'max_rounds' => $maxRounds, 'current_tier' => '', 'thrashing' => false];

        while (true) {
            $decision = $ladder->next($state);
            if ($decision['stop']) {
                $certified = $decision['action'] === 'accept';
                $merged = false;
                if ($certified && is_callable($opts['merge'] ?? null)) {
                    $mergeResult = ($opts['merge'])($lastOutcome ?? []);
                    $merged = (bool) ($mergeResult['merged'] ?? false);
                }

                return [
                    'certified' => $certified,
                    'merged' => $merged,
                    'rounds' => (int) $state['round'],
                    'tier_history' => $history,
                    'final_reason' => (string) $decision['reason'],
                    'spec' => $spec,
                    'convergence' => $ledger->convergence($thrashThreshold),
                    'last_outcome' => $lastOutcome,
                ];
            }

            $tier = (string) $decision['next_tier'];
            $state['current_tier'] = $tier;
            $state['round'] = (int) $decision['round'];

            $executor = $tierExecutors[$tier] ?? null;
            if (! is_callable($executor)) {
                // Tier not wired yet => record the skip and let the ladder escalate to the next tier.
                $history[] = ['tier' => $tier, 'round' => $state['round'], 'skipped' => true, 'reason' => 'tier_not_wired'];

                continue;
            }

            // A throwing tier executor (provider crash, transient fault) must NOT abort the whole conduct —
            // it is recorded as a failed round so the ladder escalates to a stronger tier (fail-forward).
            try {
                // ACDE X3 — append a DIMENSION-SPECIFIC re-attempt directive routed from the LAST round's
                // namespaced cert reasons, so the next tier fixes exactly the dimension that failed instead of
                // re-rolling against generic guidance. Default OFF / round 1 / no reasons => '' => byte-identical.
                $outcome = (array) $executor($goal, $ledger->guidance().$this->dimensionDirective($lastOutcome), $spec, $state['round']);
            } catch (\Throwable $e) {
                $outcome = ['certified' => false, 'reason' => 'tier_threw:'.mb_substr($e->getMessage(), 0, 160)];
            }
            $lastOutcome = $outcome;
            $certified = (bool) ($outcome['certified'] ?? false);
            $ledger->record(
                (string) ($outcome['strategy'] ?? $tier),
                (string) ($outcome['provider'] ?? ''),
                $certified,
                (string) ($outcome['reason'] ?? ''),
                (string) ($outcome['signature'] ?? ''),
            );
            $history[] = ['tier' => $tier, 'round' => $state['round'], 'certified' => $certified, 'reason' => (string) ($outcome['reason'] ?? '')];

            $state['certified'] = $certified;
            $state['thrashing'] = (bool) ($ledger->convergence($thrashThreshold)['thrashing'] ?? false);
        }
    }

    /**
     * ACDE X3 — route the previous round's namespaced cert reasons to a dimension-specific re-attempt directive.
     * Returns '' (so the guidance is byte-identical) when the routing flag is OFF, on the first round (no
     * lastOutcome), or when no reason maps to a known dimension. Reads the outcome's `reasons` array when
     * present, else its single `reason` string.
     *
     * @param  array<string,mixed>|null  $lastOutcome
     */
    private function dimensionDirective(?array $lastOutcome): string
    {
        if ($lastOutcome === null || ! (bool) config('atlas.loop.rejection_dimension_routing_enabled', false)) {
            return '';
        }
        $reasons = is_array($lastOutcome['reasons'] ?? null)
            ? array_map(static fn (mixed $r): string => (string) $r, array_values($lastOutcome['reasons']))
            : ((string) ($lastOutcome['reason'] ?? '') !== '' ? [(string) $lastOutcome['reason']] : []);
        if ($reasons === []) {
            return '';
        }
        $routed = ($this->rejectionRouter ?? new AtlasLoopRejectionDimensionRouter)->route($reasons);
        $directive = (string) ($routed['directive'] ?? '');
        if ($directive === '') {
            return '';
        }

        return "\n\nFIX THIS SPECIFICALLY (the cert rejected on: ".implode(', ', $routed['dimensions'])."):\n".$directive;
    }
}
