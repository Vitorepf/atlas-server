<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * §3 · ARCHITECT PHASE — the design↔critique critic made GROUNDED (the phase that guarantees quality).
 *
 * The {@see AtlasLoopProjectionEngine} is a sound convergence MACHINE (writer ≠ critic, set-theoretic
 * fixpoint, oscillation ⇒ PARK). But the {@see AtlasLoopProjectionWorker}'s v1 designer/critic are
 * SCRIPTED: the critic raises ONE fixed obligation and resolves it next round, so EVERY non-degenerate
 * envelope converges in two rounds and a garbage design converges identically to an excellent one. The
 * critic never inspects the design against the scope's REAL constraints — it is choreography, not a gate.
 *
 * This class replaces that choreography with a critic that BITES on GROUND TRUTH. It reads the
 * {@see AtlasLoopScopeComprehensionModel} (the non-gameable who-calls-who edges) and forces the design to
 * carry an obligation for every REAL risk the evolution incurs:
 *   - DESIGNER (round 1) seeds `behavior_preserved` (mutop) + `contract_upheld` (chartest) on the target —
 *     the floor every evolution must clear, grounded on the target's real symbol.
 *   - CRITIC (writer ≠ judge) reads the target's REAL production callers and raises a `consumer_intact`
 *     obligation, ONE per round, for each caller the design has not yet covered — so a design that would
 *     silently break a real caller cannot converge until it accounts for that caller. When every real
 *     caller is covered it raises a `mutation_killed` obligation on the target (the anti-empty-test floor,
 *     so a zero-caller leaf still earns a material critique), then resolves what it raised ⇒ convergence.
 *
 * The convergence is therefore a REAL fact: the projected contract provably contains a `consumer_intact`
 * obligation for every measured caller of the target, which the downstream cert chain enforces. A target
 * whose caller fan-out exceeds the engine's round budget never stabilizes ⇒ the engine PARKS it
 * (blast-radius too large to auto-originate without review) — a reachable, ground-truth-driven rejection,
 * never a rubber-stamp.
 *
 * HONEST RESIDUAL (§9, model-bound): a frontier engine would propose DEEPER obligations (a perf bound, a
 * cross-file contract the edges don't name). This pair is the deterministic FLOOR — grounded, ungameable,
 * and sufficient that even a modest engine converges on a contract that protects every real caller; the
 * richer obligations layer on top through the engine's same admit() seam. The role SEPARATION + the
 * grounded convergence are deterministic and proven; the obligation DEPTH is the frontier model's lift.
 */
final class AtlasLoopGroundedProjectionRoles
{
    public function __construct(private readonly AtlasLoopScopeComprehensionModel $model)
    {
    }

    /**
     * Build the grounded (designer, critic) closures for {@see AtlasLoopProjectionEngine::project()} over a
     * single evolution target (a scope rel-path). The closures share the engine's normalization, so the
     * keys they emit collide exactly with what the engine stores.
     *
     * @param  string  $relTarget  the evolution's target file (the producer envelope's target_path)
     * @param  list<array<string,mixed>>  $extraSeeds  additional grounded obligations (the §3 cross-model critique's
     *                                                  contribution); each is engine-validated again here, so an
     *                                                  ungrounded one is harmless. [] ⇒ the deterministic floor alone.
     * @return array{designer: callable(int, list<array<string,mixed>>): list<array<string,mixed>>, critic: callable(list<array<string,mixed>>): array{add: list<array<string,mixed>>, resolved: list<string>}, consumer_count: int, consumers: list<string>, forbidden: bool}
     */
    public function forTarget(string $relTarget, array $extraSeeds = [], ?string $objectiveKind = null): array
    {
        $engine = new AtlasLoopProjectionEngine;
        $target = $this->norm($relTarget);
        $forbidden = $this->isForbiddenTarget($relTarget);
        $realMutop = (string) array_key_first(AtlasLoopMutationOperators::map());
        $originalConsumers = $this->realConsumerPaths($relTarget);
        $consumers = array_map(fn (string $c): string => $this->norm($c), $originalConsumers);
        $testPath = 'tests/'.$this->classOf($relTarget).'Test.php';
        $raised = []; // engine-obligation-key => true (everything the critic raised, for the resolve step)

        // §2 WORK-TYPE CONTRACT — seed the obligation this work TYPE owes (a dedup→complexity_reduced, a
        // bug-fix→red_to_green, a perf→perf_bound), so the projected contract provably commits the evolution
        // to its own anti-Goodhart proof. Unknown/absent kind ⇒ no extra obligation (floor unchanged).
        $typeSeeds = [];
        if ($objectiveKind !== null && trim($objectiveKind) !== '') {
            $mandatory = (new AtlasLoopWorkTypeContract)->mandatoryObligation($objectiveKind, $target, $testPath);
            if ($mandatory !== null) {
                $typeSeeds[] = $mandatory;
            }
        }

        $designer = static function (int $round, array $current) use ($target, $realMutop, $testPath, $extraSeeds, $typeSeeds): array {
            if ($round !== 1) {
                return []; // later rounds let the critic engage; the design only seeds the floor once
            }

            // The deterministic floor + the work-type's mandatory proof + the cross-model critique's grounded
            // deepening (the engine re-validates every tuple on admit, so an ungrounded seed is silently
            // dropped — never a weakening).
            return array_merge([
                ['kind' => 'behavior_preserved', 'target_symbol' => $target, 'assertion_ref' => 'mutop:'.$realMutop],
                ['kind' => 'contract_upheld', 'target_symbol' => $target, 'assertion_ref' => 'chartest:'.$testPath.'::test_contract'],
            ], array_values($typeSeeds), array_values($extraSeeds));
        };

        $critic = function (array $current) use ($engine, $target, $consumers, $realMutop, &$raised): array {
            $coveredConsumers = [];
            $hasMutationKilled = false;
            foreach ($current as $o) {
                $kind = (string) ($o['kind'] ?? '');
                $sym = (string) ($o['target_symbol'] ?? '');
                if ($kind === 'consumer_intact') {
                    $coveredConsumers[$sym] = true;
                } elseif ($kind === 'mutation_killed' && $sym === $target) {
                    $hasMutationKilled = true;
                }
            }

            // (1) Raise the FIRST real caller the design has not yet protected — one per round, so the
            //     back-and-forth is genuine and the round budget bounds the blast radius.
            foreach ($consumers as $consumer) {
                if (! isset($coveredConsumers[$consumer])) {
                    $ob = ['kind' => 'consumer_intact', 'target_symbol' => $consumer, 'assertion_ref' => 'consumer:'.$consumer];
                    $key = $engine->obligationKey($ob);
                    if ($key !== null) {
                        $raised[$key] = true;

                        return ['add' => [$ob], 'resolved' => []];
                    }
                }
            }

            // (2) Every real caller is covered — force the anti-empty-test floor once (a behavior test that
            //     does not kill a mutant in the target is not a behavior test), so even a zero-caller leaf
            //     earns a material critique and the critic provably ENGAGED.
            if (! $hasMutationKilled) {
                $ob = ['kind' => 'mutation_killed', 'target_symbol' => $target, 'assertion_ref' => 'mutop:'.$realMutop];
                $key = $engine->obligationKey($ob);
                if ($key !== null) {
                    $raised[$key] = true;

                    return ['add' => [$ob], 'resolved' => []];
                }
            }

            // (3) Nothing left to raise — resolve everything raised so the engine sees an engaged critic at
            //     a stable set ⇒ convergence.
            return ['add' => [], 'resolved' => array_keys($raised)];
        };

        return ['designer' => $designer, 'critic' => $critic, 'consumer_count' => count($consumers), 'consumers' => $originalConsumers, 'forbidden' => $forbidden];
    }

    /**
     * Is the evolution target a pétreo/FORBIDDEN cert organ (the frozen judge, the projection engine, the
     * harness guard, …)? The model's `forbidden` set IS {@see AtlasLoopHarnessGuard::isForbiddenSelfTarget}.
     * A principal engineer never designs a change to the gate that judges them — so the architect phase must
     * refuse to project one (the worker parks it explicitly), not rely on the incidental caller fan-out of a
     * heavily-used organ. This is the design-time mirror of the cert-time harness guard: the constitution
     * holds at BOTH ends, so the loop can never improve its way into sawing off the branch it sits on.
     */
    private function isForbiddenTarget(string $relTarget): bool
    {
        $targetNorm = $this->norm($relTarget);
        if ($targetNorm === '') {
            return false;
        }
        foreach ($this->model->forbidden as $forbidden) {
            if ($this->norm((string) $forbidden) === $targetNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * The target's measured production callers in ORIGINAL case (the obligation key is lossy-lowercased, but
     * the downstream consumer-contract command resolver needs the real file path), de-duplicated by their
     * normalized form. An unmeasured target (no edges entry) yields [] — the critic then falls to the
     * mutation-killed floor rather than fabricating a caller.
     *
     * @return list<string>
     */
    private function realConsumerPaths(string $relTarget): array
    {
        $callers = $this->model->callerPathsFor($relTarget) ?? [];
        $targetNorm = $this->norm($relTarget);
        $out = [];
        foreach ($callers as $caller) {
            $caller = ltrim(trim((string) $caller), '/');
            $norm = $this->norm($caller);
            if ($norm !== '' && $norm !== $targetNorm && ! isset($out[$norm])) {
                $out[$norm] = $caller; // a self-edge is not an external caller to protect
            }
        }

        return array_values($out);
    }

    /** Mirror {@see AtlasLoopProjectionEngine}'s fqcn normalization so emitted keys collide with stored ones. */
    private function norm(string $symbol): string
    {
        return strtolower(ltrim(preg_replace('/\s+/', '', $symbol) ?? '', '\\'));
    }

    private function classOf(string $relTarget): string
    {
        $base = basename(trim($relTarget));

        return str_ends_with($base, '.php') ? substr($base, 0, -4) : $base;
    }
}
