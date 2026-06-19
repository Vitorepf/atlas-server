<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * MODEL-ROLE SEQUENCER + PARK-ON-OUTAGE + COST — the deterministic control logic of a Loop delivery.
 *
 * A delivery runs three model ROLES in order: designer (frontier projection) -> critic (independent
 * cross-model critique, the phase that GUARANTEES quality) -> implementer (the muscle that writes code).
 * This class is the pure sequencer + the two decisions that govern it; the model calls themselves are
 * model-bound and out of scope.
 *
 * PARK-ON-OUTAGE (the canonical guardrail): if the CRITIC role is down (the judge — e.g. Hermes-down),
 * we PARK rather than ship work that was never critiqued ("leaps park, refactor-supply continues" — NEVER
 * ship un-critiqued work). If the DESIGNER role is down there is nothing to critique or implement, so we
 * park too. The implementer being down alone does not park the projection; designer+critic up is what
 * lets the loop proceed. This is fail-CLOSED on the quality gate, by construction.
 *
 * COST: projection turns are charged into a bounded budget with a throttle at 80% of the ceiling and a
 * hard over-budget signal at the ceiling — so a runaway projection loop cannot silently burn the budget.
 *
 * PURE: no provider, no DB, no clock. Deterministic in -> deterministic out.
 */
final class AtlasLoopOrchestrator
{
    private const ROLE_DESIGNER = 'designer';
    private const ROLE_CRITIC = 'critic';
    private const ROLE_IMPLEMENTER = 'implementer';

    private const THROTTLE_FRACTION = 0.8;

    /**
     * Decide the role sequence + whether the delivery may proceed or must park.
     *
     * @param array<string,bool> $roleAvailability map of roleKey => is-up (e.g. ['designer'=>true,'critic'=>true,'implementer'=>true])
     * @return array{sequence: list<string>, can_proceed: bool, park: bool, park_reason: ?string}
     */
    public function plan(array $roleAvailability): array
    {
        $designerUp = $this->isUp($roleAvailability, self::ROLE_DESIGNER);
        $criticUp = $this->isUp($roleAvailability, self::ROLE_CRITIC);

        // PARK decisions (order: critic first — the un-critiqued-work guardrail is the headline reason).
        if (! $criticUp) {
            return $this->parked(
                'critic_unavailable: the judge is down — never ship un-critiqued work (leaps park, refactor-supply continues)',
            );
        }

        if (! $designerUp) {
            return $this->parked(
                'designer_unavailable: no frontier projection to critique or implement — park',
            );
        }

        // Designer + critic are up: proceed. The full role sequence is fixed and ordered.
        return [
            'sequence' => [self::ROLE_DESIGNER, self::ROLE_CRITIC, self::ROLE_IMPLEMENTER],
            'can_proceed' => true,
            'park' => false,
            'park_reason' => null,
        ];
    }

    /**
     * Charge one projection turn into the cost budget; report throttle (>=80% ceiling) and over (>=ceiling).
     *
     * @return array{accrued: float, throttled: bool, over: bool}
     */
    public function chargeCost(float $accruedCost, float $turnCost, float $ceiling): array
    {
        $accrued = $accruedCost + $turnCost;

        return [
            'accrued' => $accrued,
            'throttled' => $accrued >= (self::THROTTLE_FRACTION * $ceiling),
            'over' => $accrued >= $ceiling,
        ];
    }

    /**
     * @return array{sequence: list<string>, can_proceed: bool, park: bool, park_reason: string}
     */
    private function parked(string $reason): array
    {
        return [
            'sequence' => [],
            'can_proceed' => false,
            'park' => true,
            'park_reason' => $reason,
        ];
    }

    /**
     * @param array<string,bool> $roleAvailability
     */
    private function isUp(array $roleAvailability, string $role): bool
    {
        return ($roleAvailability[$role] ?? false) === true;
    }
}
