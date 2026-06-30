<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Facts-only gate deciding whether the Atlas-native worker swarm may execute packets CONTINUOUSLY
 * without operator or external-provider dependency.
 *
 * INVARIANTS:
 *   - Reads {@see AtlasNativeWorkerCapabilityRegistry} facts + observed component readiness.
 *   - Returns ready=false with EXACT named blockers until every required native component is verified.
 *   - Returns ready=true ONLY when:
 *       runtime_owner === atlas_native AND
 *       server_side_verification_available === true AND
 *       rollback_available === true AND
 *       every required native component reports verified=true.
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NO scalar hype score, NO provider call, NO shell, NO git, NO human dependency.
 */
final class AtlasNativeWorkerReadinessGate
{
    public const SCHEMA = 'atlas.native_worker.readiness.v1';

    public const STALE_HEARTBEAT_THRESHOLD_SECONDS = 300;

    public const DEFAULT_WORKER_FEED_FLOOR = 1.0;

    public const REQUIRED_COMPONENTS = [
        'patch_planner',
        'scoped_patch_applier',
        'gate_runner',
        'evidence_writer',
        'rollback_runner',
        'learning_receipt_writer',
    ];

    public function __construct(private readonly AtlasNativeWorkerCapabilityRegistry $registry) {}

    /**
     * @param  array{
     *     runtime_owner?:string,
     *     server_side_verification_available?:bool,
     *     rollback_available?:bool,
     *     components?:array<string,array{present?:bool, verified?:bool}>
     * }  $observed
     * @return array{schema:string, ready:bool, blockers:list<string>, components_required:list<string>, components_verified:list<string>}
     */
    public function evaluate(array $observed): array
    {
        $blockers = [];

        $runtimeOwner = (string) ($observed['runtime_owner'] ?? '');
        if ($runtimeOwner !== AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER) {
            $blockers[] = 'runtime_owner_not_atlas_native:'.($runtimeOwner === '' ? 'missing' : $runtimeOwner);
        }
        if (! (bool) ($observed['server_side_verification_available'] ?? false)) {
            $blockers[] = 'server_side_verification_unavailable';
        }
        if (! (bool) ($observed['rollback_available'] ?? false)) {
            $blockers[] = 'rollback_unavailable';
        }

        if (! array_key_exists('queue_pressure', $observed)) {
            $blockers[] = 'missing_queue_pressure_facts';
        } else {
            $queuePressure = (int) ($observed['queue_pressure'] ?? 0);
            $availableWorkers = (int) ($observed['available_worker_count'] ?? 0);
            if ($queuePressure > 0 && $availableWorkers === 0) {
                $blockers[] = 'no_available_workers_with_queue_pressure';
            }

            // Worker-feed floor: when claimable (or servable) work per active
            // worker is below the floor, readiness must block NOW with an
            // explicit blocker — never wait until workers actually hit
            // no_claimable_task before reporting the shortfall.
            $feedFloor = (float) ($observed['worker_feed_floor'] ?? self::DEFAULT_WORKER_FEED_FLOOR);
            if (array_key_exists('claimable_per_active_worker', $observed)) {
                $claimablePerActiveWorker = (float) $observed['claimable_per_active_worker'];
                if ($claimablePerActiveWorker < $feedFloor) {
                    $blockers[] = 'queue_feed_floor:claimable_per_active_worker_'.$claimablePerActiveWorker.'_below_'.$feedFloor;
                }
            } elseif (array_key_exists('servable_per_worker', $observed)) {
                $servablePerWorker = (float) $observed['servable_per_worker'];
                if ($servablePerWorker < $feedFloor) {
                    $blockers[] = 'queue_feed_floor:servable_per_worker_'.$servablePerWorker.'_below_'.$feedFloor;
                }
            }
        }

        $heartbeatAge = $observed['heartbeat_age_seconds'] ?? null;
        if (is_int($heartbeatAge) && $heartbeatAge > self::STALE_HEARTBEAT_THRESHOLD_SECONDS) {
            $blockers[] = 'stale_heartbeat';
        }

        if (! (bool) ($observed['command_plan_runner_available'] ?? false)) {
            $blockers[] = 'command_plan_runner_unavailable';
        }

        $components = is_array($observed['components'] ?? null) ? $observed['components'] : [];
        $verified = [];
        foreach (self::REQUIRED_COMPONENTS as $cid) {
            $row = is_array($components[$cid] ?? null) ? $components[$cid] : [];
            $present = (bool) ($row['present'] ?? false);
            $verifiedFlag = (bool) ($row['verified'] ?? false);
            if (! $present) {
                $blockers[] = 'component_missing:'.$cid;

                continue;
            }
            if (! $verifiedFlag) {
                $blockers[] = 'component_unverified:'.$cid;

                continue;
            }
            $verified[] = $cid;
        }

        // Cross-check that the capability registry agrees the final capabilities exist (catches a
        // registry/component drift that would otherwise let a partial swarm pass).
        $registryIds = array_column($this->registry->capabilities(), 'capability_id');
        foreach (AtlasNativeWorkerCapabilityRegistry::FINAL_CAPABILITY_IDS as $expected) {
            if (! in_array($expected, $registryIds, true)) {
                $blockers[] = 'registry_missing_final_capability:'.$expected;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'components_required' => self::REQUIRED_COMPONENTS,
            'components_verified' => $verified,
        ];
    }
}
