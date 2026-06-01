<?php

namespace App\Services\Ai\Aaeos;

/**
 * Stateful veto-propagation watchdog named by the AAEOS Cross-Department
 * Choreography doc. Tracks which departments are currently paused as vetos are
 * raised and lifted across a coordination session, using the pure veto rules in
 * AtlasCrossDepartmentChoreographyService. Enforces the <=10s pause SLA contract.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
 */
class AtlasVetoPropagationWatchdog
{
    public function __construct(
        private readonly AtlasCrossDepartmentChoreographyService $choreography,
    ) {}

    /**
     * Replay a sequence of veto events and compute the resulting paused-department
     * set + propagation receipts. Each event: {department: string, lift?: bool}.
     *
     * @param  array<int,array{department:string, lift?:bool}>  $events
     * @return array<string,mixed>
     */
    public function watch(array $events): array
    {
        $paused = [];
        $receipts = [];
        $finalOverride = false;

        foreach ($events as $event) {
            $dept = (string) ($event['department'] ?? '');
            $veto = $this->choreography->evaluateVeto($dept);
            if (($veto['recognized'] ?? false) !== true) {
                continue;
            }

            if (($event['lift'] ?? false) === true) {
                foreach ((array) ($veto['paused_departments'] ?? []) as $p) {
                    unset($paused[$p]);
                }
            } else {
                if (($veto['final_override'] ?? false) === true) {
                    $finalOverride = true;
                }
                foreach ((array) ($veto['paused_departments'] ?? []) as $p) {
                    $paused[$p] = true;
                }
            }
            $receipts[] = $veto;
        }

        return [
            'schema_version' => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'paused_departments' => array_values(array_keys($paused)),
            'final_override_active' => $finalOverride,
            'pause_sla_seconds' => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            'veto_receipts' => $receipts,
        ];
    }
}
