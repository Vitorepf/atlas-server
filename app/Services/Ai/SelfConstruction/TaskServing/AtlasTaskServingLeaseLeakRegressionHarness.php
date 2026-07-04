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

    /** AC2/AC3: active_leases > claimed_records, recoverable_total=0, lease_leak_detected=true */
    public const SCENARIO_LEASE_MISMATCH_WITHOUT_RECOVERABLE = 'lease_mismatch_without_recoverable';

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
            // cleanly to its claim — nothing is actually orphaned. Two lease rows for the SAME
            // task_packet_id is the more specific DUPLICATE_DRIFT class (named which task ids have
            // duplicate rows), which the inspector checks before the generic count-mismatch drift.
            self::SCENARIO_ACTIVE_LEASE_SURPLUS => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'claimed'],
                ],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                    ['lease_id' => 'L1-renewed', 'task_packet_id' => 't1'],
                ],
                'expected_parity_class' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_REGISTRY_DUPLICATE_DRIFT,
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
            // AC2/AC3: exact shape that triggers lease_leak_detected=true with recoverable_total=0.
            // 3 lease rows for two tasks but only 2 queue records — active_leases(3) > claimed_records(2).
            // Every lease maps to a matched claim (no orphan, no ghost), so recoverable=0.
            self::SCENARIO_LEASE_MISMATCH_WITHOUT_RECOVERABLE => [
                'queue_records' => [
                    ['task_packet_id' => 't1', 'status' => 'claimed'],
                    ['task_packet_id' => 't2', 'status' => 'claimed'],
                ],
                'active_leases' => [
                    ['lease_id' => 'L1', 'task_packet_id' => 't1'],
                    ['lease_id' => 'L1-renewed', 'task_packet_id' => 't1'],
                    ['lease_id' => 'L2', 'task_packet_id' => 't2'],
                ],
                'expected_parity_class' => 'lease_mismatch_without_recoverable',
                'expected_recoverable_total' => 0,
                'expected_primary_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REPAIR_REGISTRY,
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

    public const REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE = 'ghost_active_lease';

    public const REGRESSION_SCENARIO_TRUE_EXPIRED_LEASE = 'true_expired_lease';

    public const REGRESSION_SCENARIO_ORPHANED_CLAIM = 'orphaned_claim';

    public const REGRESSION_SCENARIO_RELEASED_RECOVERY = 'released_recovery';

    public const REGRESSION_SCENARIO_CLEAN_QUEUE = 'clean_queue';

    /**
     * Business-vocabulary regression scenarios, distinct from scenarios() (which is frozen at exactly
     * 5 entries by test_provides_all_five_named_scenarios and cannot grow). Each entry names its
     * input_snapshot (queue_records + active_leases), expected_classification, and
     * expected_recommended_action — verified directly against AtlasTaskServingLeaseClaimParityInspector's
     * real logic, not asserted blind:
     *   - ghost_active_lease: a lease with NO matching queue record at all (nothing to reconcile
     *     against) — CLASSIFICATION_LEASE_WITHOUT_CLAIM / ghost_active_leases, ACTION_REAP_LEASES.
     *   - true_expired_lease: a lease that outlived its task's TERMINAL record (completed) — proven
     *     leaked, not just unmatched — CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE, ACTION_REAP_LEASES.
     *   - orphaned_claim: a claimed record with no active lease behind it — CLASSIFICATION_CLAIM_WITHOUT_LEASE,
     *     ACTION_INVESTIGATE_WRITER.
     *   - released_recovery: a released (terminal) record with no lingering lease — the system already
     *     recovered cleanly — CLASSIFICATION_CLEAN_PARITY, ACTION_OBSERVE.
     *   - clean_queue: the empty baseline (no records, no leases) — CLASSIFICATION_CLEAN_PARITY,
     *     ACTION_OBSERVE.
     *
     * @return array<string, array{input_snapshot:array{queue_records:list<array<string,mixed>>, active_leases:list<array<string,mixed>>}, expected_classification:string, expected_recommended_action:string}>
     */
    public function regressionScenarios(): array
    {
        return [
            self::REGRESSION_SCENARIO_GHOST_ACTIVE_LEASE => [
                'input_snapshot' => [
                    'queue_records' => [],
                    'active_leases' => [
                        ['lease_id' => 'L-ghost', 'task_packet_id' => 't-ghost'],
                    ],
                ],
                'expected_classification' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_LEASE_WITHOUT_CLAIM,
                'expected_recommended_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES,
            ],
            self::REGRESSION_SCENARIO_TRUE_EXPIRED_LEASE => [
                'input_snapshot' => [
                    'queue_records' => [
                        ['task_packet_id' => 't-expired', 'status' => 'completed'],
                    ],
                    'active_leases' => [
                        ['lease_id' => 'L-expired', 'task_packet_id' => 't-expired'],
                    ],
                ],
                'expected_classification' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_TERMINAL_WITH_ACTIVE_LEASE,
                'expected_recommended_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_REAP_LEASES,
            ],
            self::REGRESSION_SCENARIO_ORPHANED_CLAIM => [
                'input_snapshot' => [
                    'queue_records' => [
                        ['task_packet_id' => 't-orphan', 'status' => 'claimed'],
                    ],
                    'active_leases' => [],
                ],
                'expected_classification' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLAIM_WITHOUT_LEASE,
                'expected_recommended_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_INVESTIGATE_WRITER,
            ],
            self::REGRESSION_SCENARIO_RELEASED_RECOVERY => [
                'input_snapshot' => [
                    'queue_records' => [
                        ['task_packet_id' => 't-recovered', 'status' => 'released'],
                    ],
                    'active_leases' => [],
                ],
                'expected_classification' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY,
                'expected_recommended_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_OBSERVE,
            ],
            self::REGRESSION_SCENARIO_CLEAN_QUEUE => [
                'input_snapshot' => [
                    'queue_records' => [],
                    'active_leases' => [],
                ],
                'expected_classification' => AtlasTaskServingLeaseClaimParityInspector::CLASSIFICATION_CLEAN_PARITY,
                'expected_recommended_action' => AtlasTaskServingLeaseClaimParityInspector::ACTION_OBSERVE,
            ],
        ];
    }

    /**
     * @return array{input_snapshot:array{queue_records:list<array<string,mixed>>, active_leases:list<array<string,mixed>>}, expected_classification:string, expected_recommended_action:string}|null
     */
    public function regressionScenario(string $name): ?array
    {
        return $this->regressionScenarios()[$name] ?? null;
    }
}
