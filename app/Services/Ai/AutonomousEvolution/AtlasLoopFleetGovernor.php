<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use Throwable;

/**
 * §4 · FLEET GOVERNOR — a GLOBAL cap on in-flight grinds ACROSS every campaign, so the loop never thrashes the
 * Mac. The per-campaign worker count + the resource gate bound ONE campaign; nothing bounded the FLEET. Proven
 * necessary live: when 27 stale `running` rows were resurrected they became 45 concurrent processes — a global
 * worker cap would have bounded that blast radius to the cap instead of letting it swamp the machine.
 *
 * Before a campaign spawns its next batch of workers it asks the governor how many the FLEET can still afford
 * (cap minus the grinds already running across all campaigns). Flag/cap <= 0 ⇒ unlimited (byte-identical to
 * today). Pure + deterministic over the DB task table; fail-OPEN (a count error never blocks work — it just
 * doesn't throttle, never the reverse).
 */
final class AtlasLoopFleetGovernor
{
    /** Global count of in-flight grinds across ALL campaigns (the current fleet load). */
    public function fleetInFlight(): int
    {
        try {
            return (int) AtlasLoopTask::query()->where('status', 'running')->count();
        } catch (Throwable) {
            return 0; // fail-OPEN: an unreadable count never throttles (we don't starve work on a DB blip)
        }
    }

    /**
     * How many NEW workers a campaign may spawn under the global fleet cap.
     *
     * @return array{admitted:int, fleet_in_flight:int, cap:int, throttled:bool}
     */
    public function admit(int $requested, int $globalCap): array
    {
        $requested = max(0, $requested);

        // cap <= 0 ⇒ governor disabled ⇒ unlimited (byte-identical: the supervisor uses its own worker count).
        if ($globalCap <= 0) {
            return ['admitted' => $requested, 'fleet_in_flight' => 0, 'cap' => $globalCap, 'throttled' => false];
        }

        $inFlight = $this->fleetInFlight();
        $available = max(0, $globalCap - $inFlight);
        $admitted = min($requested, $available);

        return [
            'admitted' => $admitted,
            'fleet_in_flight' => $inFlight,
            'cap' => $globalCap,
            'throttled' => $admitted < $requested,
        ];
    }
}
