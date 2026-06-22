<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * THE FLEET-WIDE master gate — the loop's §0 master switch generalized to EVERY autonomous agent. A single
 * physical "kill everything" the operator (and the apps' panic button) can hit, stored as one .env line so
 * it is robust under config:cache and greppable by the bash watchdogs.
 *
 * INVARIANTS (mirror the loop master switch — see App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch):
 *   - DEFAULT OFF / FAIL-CLOSED. Until the operator explicitly turns the fleet on, the reconciler STARTS
 *     nothing. (It may still STOP things — the gate only ever restrains spend, never permits it by default.)
 *   - The system can never turn this on for itself: it is flipped only by the operator-facing fleet
 *     on/off commands. Silence = off.
 *
 * Note: this is the GLOBAL permission. WHICH agents run within an enabled fleet is each agent's per-agent
 * desired-state ({@see AtlasAgentDesiredStateStore}). Both must say yes for an agent to be started.
 */
final class AtlasFleetMasterSwitch
{
    public const KEY = 'ATLAS_FLEET_ENABLED';

    /** Test seam ONLY — point the switch at a temp .env. Never set in production code. */
    public static ?string $envPathOverride = null;

    public static function enabled(): bool
    {
        return EnvFlagGate::isOn(self::envPath(), self::KEY);
    }

    public static function state(): string
    {
        return self::enabled() ? 'on' : 'off';
    }

    public static function on(): bool
    {
        return EnvFlagGate::write(self::envPath(), self::KEY, 'true');
    }

    public static function off(): bool
    {
        return EnvFlagGate::write(self::envPath(), self::KEY, 'false');
    }

    private static function envPath(): string
    {
        return self::$envPathOverride ?? base_path('.env');
    }
}
