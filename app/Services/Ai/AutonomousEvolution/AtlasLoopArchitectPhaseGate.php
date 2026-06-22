<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;

/**
 * §1/§3 · ARCHITECT PHASE as a REUSABLE GATE — "projetar cada evolução como principal engineer ANTES de
 * implementar", made universal.
 *
 * The {@see AtlasLoopProjectionWorker} runs the architect phase only on the rédea/projection path. But the
 * canon says EVERY evolution — dedup, orphan-wiring, bug-fix, refactor, perf — must be DESIGNED before it
 * grinds, not minted straight from its supply lane. This service extracts the architect phase into one gate a
 * lane calls per directive: it builds the grounded design↔critique roles (with the work-type's mandatory
 * obligation), runs the FROZEN {@see AtlasLoopProjectionEngine} to a content-fixpoint, and returns either an
 * ADMISSION (the converged contract: typed obligations + the enforceable consumer_contracts that protect every
 * real caller) or a SUPPRESSION (forbidden pétreo target / blast-radius too large / non-converged → the
 * directive does not become work). So a lane that adopts the gate stops minting un-designed work.
 *
 * Deterministic + provider-free (the grounded floor needs no model; the frontier-deepening seam is separate).
 * The binding axis is the work-type's own (a bug-fix binds real_target, a perf binds non_trivial), so the
 * convergence actually addresses the type's bottleneck.
 */
final class AtlasLoopArchitectPhaseGate
{
    public function __construct(
        private readonly ?AtlasLoopProjectionEngine $engine = null,
        private readonly ?AtlasLoopWorkTypeContract $contract = null,
    ) {
    }

    /**
     * Design a single evolution directive through the architect phase.
     *
     * @param  list<array<string,mixed>>  $extraSeeds  the cross-model critique's grounded deepening (the
     *                                                  frontier model's additional obligations); [] = floor only.
     * @return array{admitted:bool, reason:?string, obligations:list<array<string,mixed>>, consumer_contracts:list<array<string,mixed>>}
     */
    public function admit(AtlasLoopScopeComprehensionModel $model, string $relTarget, string $objectiveKind, string $repoRoot, int $maxRounds = 8, array $extraSeeds = []): array
    {
        $contract = $this->contract ?? new AtlasLoopWorkTypeContract;
        $roles = (new AtlasLoopGroundedProjectionRoles($model))->forTarget($relTarget, $extraSeeds, $objectiveKind);

        // PÉTREO constitution guard — never design a change to a cert organ (the lane should also exclude it,
        // but the gate is the single authority so adoption is uniform).
        if (($roles['forbidden'] ?? false) === true) {
            return $this->suppress('forbidden_target_petreo');
        }

        // BEHAVIOR-ANCHOR guard — a principal engineer never refactors UNTESTED code blind: a
        // behavior-PRESERVING evolution (its mandatory proof is a complexity/perf bound, not a red→green)
        // whose target exists but has NO sibling test cannot be safely designed — SUPPRESS it (escalate to
        // first add a characterization anchor). Behavior-CHANGING types (bug_fix/feature ⇒ red_to_green) and
        // orphan-wiring (the engine authors the wired test) are exempt. Skipped when the target is not on disk
        // (a pure-model fixture), where the model is the authority.
        if (in_array($contract->mandatoryKind($objectiveKind), ['complexity_reduced', 'perf_bound'], true)) {
            $abs = rtrim($repoRoot, '/').'/'.ltrim($relTarget, '/');
            if (is_file($abs) && (new AtlasLoopSiblingTestResolver(rtrim($repoRoot, '/') ?: null))->resolve($relTarget)['has_sibling'] !== true) {
                return $this->suppress('no_behavior_anchor');
            }
        }

        $axis = $contract->bindingAxis($objectiveKind) ?? 'wired';
        $engine = $this->engine ?? new AtlasLoopProjectionEngine;
        $result = $engine->project($axis, $roles['designer'], $roles['critic'], $maxRounds);

        if (($result['status'] ?? '') !== 'converged') {
            // blast-radius too large / oscillation ⇒ not designable as one safe step ⇒ suppress (escalate).
            return $this->suppress((string) ($result['reason'] ?? 'not_converged'));
        }

        $obligations = array_values((array) ($result['obligations'] ?? []));
        $consumerContracts = $this->consumerContracts($obligations, (array) ($roles['consumers'] ?? []), $relTarget, $repoRoot);

        return ['admitted' => true, 'reason' => null, 'obligations' => $obligations, 'consumer_contracts' => $consumerContracts];
    }

    /**
     * @return array{admitted:false, reason:string, obligations:list<array<string,mixed>>, consumer_contracts:list<array<string,mixed>>}
     */
    private function suppress(string $reason): array
    {
        return ['admitted' => false, 'reason' => $reason, 'obligations' => [], 'consumer_contracts' => []];
    }

    /**
     * Translate the converged consumer_intact obligations into the enforceable consumer_contracts the
     * certifier's cross-file consumer gate replays — the SAME bridge {@see AtlasLoopProjectionWorker} uses.
     *
     * @param  list<array<string,mixed>>  $obligations
     * @param  list<string>  $consumers
     * @return list<array<string,mixed>>
     */
    private function consumerContracts(array $obligations, array $consumers, string $relTarget, string $repoRoot): array
    {
        if ($consumers === []) {
            return [];
        }
        $changedSymbol = $this->classOf($relTarget);
        $resolver = new AtlasLoopSiblingTestResolver(rtrim($repoRoot, '/') ?: null);
        $commandFor = static function (string $caller) use ($resolver): ?string {
            $sibling = $resolver->resolve($caller);
            $path = (string) ($sibling['sibling_path'] ?? '');

            return ($sibling['has_sibling'] ?? false) && $path !== '' ? './vendor/bin/phpunit '.escapeshellarg($path) : null;
        };

        return (new AtlasLoopProjectionObligationContracts)->toConsumerContracts($obligations, $consumers, $changedSymbol, $commandFor);
    }

    private function classOf(string $relTarget): string
    {
        $base = basename(trim($relTarget));

        return str_ends_with($base, '.php') ? substr($base, 0, -4) : $base;
    }
}
