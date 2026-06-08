<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

/**
 * Turn-wide token-unit ceiling for a single conductor dispatch (the K arms of one
 * turn share one pool).
 *
 * This is the Atlas-sovereign counterpart to the Dynamic Workflows budget the
 * reverse-engineering surfaced — with one deliberate improvement: where the native
 * feature THROWS at the ceiling (crashing a run mid-flight), this accumulator
 * RETURNS a refusal so the caller can emit a clean `turn_budget_exhausted` failure
 * outcome that the executor's existing tie-break simply routes around. Antifragile,
 * not fragile.
 *
 * The ceiling is in token-units; a zero/negative ceiling disables the gate (the
 * "no target => unbounded" case, exactly like the native budget.total === null).
 * It is a pure accumulator: estimation lives at the call boundary, so this stays
 * trivially testable and free of provider coupling.
 */
final class AtlasConductorTurnBudget
{
    private float $spent = 0.0;

    public function __construct(private readonly float $ceilingUnits) {}

    public function enabled(): bool
    {
        return $this->ceilingUnits > 0.0;
    }

    /**
     * Try to consume units. Returns false (without consuming) when the spend would
     * exceed the ceiling — the caller turns that into a held failure outcome, never
     * a throw. With the gate disabled, consumption is always allowed.
     */
    public function tryConsume(float $units): bool
    {
        $units = max(0.0, $units);

        if (! $this->enabled()) {
            $this->spent += $units;

            return true;
        }

        if ($this->spent + $units > $this->ceilingUnits) {
            return false;
        }

        $this->spent += $units;

        return true;
    }

    public function spent(): float
    {
        return $this->spent;
    }

    public function remaining(): float
    {
        return $this->enabled() ? max(0.0, $this->ceilingUnits - $this->spent) : INF;
    }

    public function exhausted(): bool
    {
        return $this->enabled() && $this->spent >= $this->ceilingUnits;
    }

    public function ceiling(): float
    {
        return $this->ceilingUnits;
    }
}
