<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * DIGEST PRESENCE PREDICATES extracted from the god-class
 * {@see AgentControlPlaneMultiAgentLoopCertificationService}.
 *
 * Owns every terminal-loop digest-presence predicate (8). The runtime
 * service delegates each method to this collaborator through thin
 * byte-identical delegators.
 */
final class AgentControlPlaneMultiAgentLoopDigestPredicates
{
    private const DEFAULT_STALE_AGE_MINUTES = 60;

    /**
     * Classifies one digest evidence record into the real activity buckets a
     * loop summary must distinguish — never collapses "a commit happened"
     * into "value was delivered".
     *
     * delivered_value — proof_commands non-empty OR outcome_events non-empty.
     *   A commit alone is NEVER sufficient; without a runnable proof command
     *   or a recorded outcome event, delivered_value stays false and
     *   missing_evidence names what's absent.
     * productive_active — active_leases > 0 and delivered_value is not yet true
     *   (work is in flight, no proof landed yet this tick).
     * blocked — blocked_count > 0.
     * stale — no active leases and last_activity_age_minutes exceeds the
     *   stale floor (default 60).
     * vanity_activity — commits_count > 0 but neither delivered_value nor
     *   productive_active is true: code moved with no proof and no
     *   in-flight lease behind it — activity for its own sake.
     *
     * digest_flags lists every bucket that is true (a record can be both
     * blocked and stale, for example). missing_evidence explains exactly
     * what would need to exist for delivered_value to flip true.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function classify(array $evidence): array
    {
        $proofCommands = array_values(array_filter(array_map('strval', (array) ($evidence['proof_commands'] ?? []))));
        $outcomeEvents = array_values(array_filter(array_map('strval', (array) ($evidence['outcome_events'] ?? []))));
        $activeLeases = max(0, (int) ($evidence['active_leases'] ?? 0));
        $blockedCount = max(0, (int) ($evidence['blocked_count'] ?? 0));
        $commitsCount = max(0, (int) ($evidence['commits_count'] ?? 0));
        $lastActivityAgeMinutes = (int) ($evidence['last_activity_age_minutes'] ?? 0);
        $staleAgeMinutes = (int) ($evidence['stale_age_minutes'] ?? self::DEFAULT_STALE_AGE_MINUTES);

        $deliveredValue = $proofCommands !== [] || $outcomeEvents !== [];
        $productiveActive = $activeLeases > 0 && ! $deliveredValue;
        $blocked = $blockedCount > 0;
        $stale = $activeLeases === 0 && $lastActivityAgeMinutes > $staleAgeMinutes;
        $vanityActivity = $commitsCount > 0 && ! $deliveredValue && ! $productiveActive;

        $missingEvidence = [];
        if (! $deliveredValue) {
            $missingEvidence[] = 'proof_commands_or_outcome_events';
        }

        $digestFlags = [];
        foreach ([
            'delivered_value' => $deliveredValue,
            'productive_active' => $productiveActive,
            'blocked' => $blocked,
            'stale' => $stale,
            'vanity_activity' => $vanityActivity,
        ] as $flag => $isTrue) {
            if ($isTrue) {
                $digestFlags[] = $flag;
            }
        }

        return [
            'delivered_value' => $deliveredValue,
            'productive_active' => $productiveActive,
            'blocked' => $blocked,
            'stale' => $stale,
            'vanity_activity' => $vanityActivity,
            'digest_flags' => $digestFlags,
            'missing_evidence' => $missingEvidence,
        ];
    }

public function terminalLoopFleetLaunchPlanPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchPlanPresent();
    }


public function terminalLoopFleetReplenishmentPlanPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetReplenishmentPlanPresent();
    }


public function terminalLoopFleetResumeRollupPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetResumeRollupPresent();
    }


public function terminalLoopFleetEvidenceRollupPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetEvidenceRollupPresent();
    }


public function terminalLoopFleetOperatorHandoffPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetOperatorHandoffPresent();
    }


public function terminalLoopFleetLaneIsolationPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaneIsolationPresent();
    }


public function terminalLoopCycleSupervisorPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::cycleSupervisorPresent();
    }


public function terminalLoopFleetLaunchRunbookPresent(): bool
    {
        return \App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchRunbookPresent();
    }

}
