<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure, advisory-only repair-plan compiler over a lease/claim PARITY REPORT (the shape produced by
 * {@see AtlasTaskServingLeaseClaimParityInspector}, or any equivalent array). Names which EXISTING
 * command or service should run, which records are safe to ignore, and which cases need a writer fix —
 * never mutates the queue itself. The cheapest, safest action (reap_leases for recoverable backlog)
 * always comes before any heavier registry repair.
 *
 * Input shape (subset of the parity inspector's output is enough):
 *   {classification?:string, active_leases:int, claimed_records:int, recoverable_candidates:{total:int}}
 */
final class AtlasTaskServingLeaseMismatchRepairPlan
{
    public const SCHEMA = 'atlas.self_construction.task_serving.lease_mismatch_repair_plan.v1';

    public const ACTION_REAP_LEASES = 'atlas:acp:reap-leases';

    public const ACTION_REPAIR_REGISTRY = 'repair_registry';

    public const ACTION_INVESTIGATE_WRITER = 'investigate_writer';

    public const ACTION_OBSERVE = 'observe';

    /**
     * @param  array<string, mixed>  $parityReport
     * @return array<string, mixed>
     */
    public function compile(array $parityReport): array
    {
        $classification = (string) ($parityReport['classification'] ?? '');
        $recoverableTotal = (int) data_get($parityReport, 'recoverable_candidates.total', 0);
        $activeLeases = (int) ($parityReport['active_leases'] ?? 0);
        $claimedRecords = (int) ($parityReport['claimed_records'] ?? 0);

        $steps = [];

        if ($recoverableTotal > 0) {
            $steps[] = $this->step(
                self::ACTION_REAP_LEASES,
                'recoverable_candidates_present:'.$recoverableTotal,
                'safe',
                'resolves_recoverable_lease_claim_mismatch',
            );
        }

        if ($recoverableTotal === 0 && $activeLeases > $claimedRecords) {
            $steps[] = $this->step(
                self::ACTION_REPAIR_REGISTRY,
                'non_recoverable_active_lease_surplus:active='.$activeLeases.',claimed='.$claimedRecords,
                'caution_no_destructive_deletion',
                'reduces_lease_registry_drift',
            );
        }

        if ($classification === 'claim_without_lease_drift') {
            $steps[] = $this->step(
                self::ACTION_INVESTIGATE_WRITER,
                'claim_without_lease_detected',
                'safe',
                'clarifies_writer_responsible_for_orphan_claim',
            );
        }

        if ($steps === []) {
            $steps[] = $this->step(self::ACTION_OBSERVE, 'clean_parity', 'clean', 'none');
        }

        return [
            'schema' => self::SCHEMA,
            'steps' => $steps,
            'mutates_queue' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $action, string $reason, string $safetyLevel, string $expectedHealthDelta): array
    {
        return [
            'action' => $action,
            'reason' => $reason,
            'safety_level' => $safetyLevel,
            'expected_health_delta' => $expectedHealthDelta,
        ];
    }
}
