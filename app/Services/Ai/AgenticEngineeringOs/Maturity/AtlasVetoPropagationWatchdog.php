<?php

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

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
    public const FIELD_PAUSED_DEPARTMENTS = 'paused_departments';
    public const FIELD_DEPARTMENT = 'department';
    public const FIELD_RECOGNIZED = 'recognized';
    public const FIELD_LIFT = 'lift';
    public const FIELD_FINAL_OVERRIDE_ACTIVE = 'final_override_active';
    public const FIELD_PAUSE_SLA_SECONDS = 'pause_sla_seconds';
    public const FIELD_FINAL_OVERRIDE = 'final_override';
    public const FIELD_VETO_RECEIPTS = 'veto_receipts';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
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
            if (! is_array($event)) {
                continue;
            }
            $dept = AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_DEPARTMENT] ?? null) ?? '';
            $veto = $this->choreography->evaluateVeto($dept);
            if (($veto[self::FIELD_RECOGNIZED] ?? false) !== true) {
                continue;
            }

            if (($event[self::FIELD_LIFT] ?? false) === true) {
                foreach (AiValueNormalizer::arrayOrEmpty($veto[self::FIELD_PAUSED_DEPARTMENTS] ?? null) as $p) {
                    $pausedKey = AiValueNormalizer::trimmedStringOrNull($p);
                    if ($pausedKey === null) {
                        continue;
                    }
                    unset($paused[$pausedKey]);
                }
            } else {
                if (($veto[self::FIELD_FINAL_OVERRIDE] ?? false) === true) {
                    $finalOverride = true;
                }
                foreach (AiValueNormalizer::arrayOrEmpty($veto[self::FIELD_PAUSED_DEPARTMENTS] ?? null) as $p) {
                    $pausedKey = AiValueNormalizer::trimmedStringOrNull($p);
                    if ($pausedKey === null) {
                        continue;
                    }
                    $paused[$pausedKey] = true;
                }
            }
            $receipts[] = $veto;
        }

        return [
            self::FIELD_SCHEMA_VERSION => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            self::FIELD_PAUSED_DEPARTMENTS => array_values(array_keys($paused)),
            self::FIELD_FINAL_OVERRIDE_ACTIVE => $finalOverride,
            self::FIELD_PAUSE_SLA_SECONDS => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            self::FIELD_VETO_RECEIPTS => $receipts,
        ];
    }
}
