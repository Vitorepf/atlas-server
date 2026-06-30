<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure scenario generator for lease/claim health invariants. Produces compact in-memory fixtures
 * covering clean, recoverable, leaked, and terminal-drift shapes so future changes to
 * AtlasTaskCoordinationHealthService, lease recovery, or registry rebuild can be regression-tested
 * without building a full queue fixture every time.
 *
 * Each scenario pairs minimal queue_records/active_leases with the expected parity classification,
 * recoverable total, and primary recommended action that
 * {@see AtlasTaskServingLeaseClaimParityInspector::inspect()} should produce for that shape.
 *
 * Pure PHP array generation — no DB, filesystem, provider, process, or git side effects.
 */
final class AtlasTaskServingLeaseLeakRegressionHarness
{
    public const SCHEMA = 'atlas.self_construction.task_serving.lease_leak_regression_harness.v1';

    public const SCENARIO_CLEAN_PARITY = 'clean_parity';

    public const SCENARIO_RECOVERABLE_ORPHAN = 'recoverable_orphan';

    public const SCENARIO_ACTIVE_LEASE_SURPLUS = 'active_lease_surplus';

    public const SCENARIO_CLAIMED_WITHOUT_LEASE = 'claimed_without_lease';

    public const SCENARIO_TERMINAL_RECORD_WITH_ACTIVE_LEASE = 'terminal_record_with_active_lease';

    /**
     * @return array<string, array{queue_records:list<array<string,mixed>>, active_leases:list<array<string,mixed>>, expected_parity_class:string, expected_recoverable_total:int, expected_primary_action:string}>
     */
    public function scenarios(): array
    {
        return [
            self::SCENARIO_CLEAN_PARITY => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'claimed'],
                ],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                ],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY,
                'expected_recoverable_total' => 0,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_OBSERVE,
            ],
            self::SCENARIO_RECOVERABLE_ORPHAN => [
                'queue_records' => [],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                ],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_WITHOUT_CLAIM,
                'expected_recoverable_total' => 1,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES,
            ],
            // The lease-mismatch class that currently keeps health false while the queue stays
            // servable: more lease rows than queue records, but every active lease still resolves
            // cleanly to its claim — nothing is actually orphaned.
            self::SCENARIO_ACTIVE_LEASE_SURPLUS => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'claimed'],
                ],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                    ['lease_id' => 'L1-renewed', 'task_packet_id' => 't1'],
                ],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_REGISTRY_DRIFT,
                'expected_recoverable_total' => 0,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REPAIR_REGISTRY,
            ],
            self::SCENARIO_CLAIMED_WITHOUT_LEASE => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'claimed'],
                ],
                'active_leases' => [],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLAIM_WITHOUT_LEASE,
                'expected_recoverable_total' => 0,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_INVESTIGATE_WRITER,
            ],
            self::SCENARIO_TERMINAL_RECORD_WITH_ACTIVE_LEASE => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'completed'],
                ],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                ],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE,
                'expected_recoverable_total' => 1,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES,
            ],
        ];
    }

    /**
     * @return array{queue_records:list<array<string,mixed>>, active_leases:list<array<string,mixed>>, expected_parity_class:string, expected_recoverable_total:int, expected_primary_action:string}|null
     */
    public function scenario(string $name): ?array
    {
        return $this->scenarios()[$name] ?? null;
    }
}
