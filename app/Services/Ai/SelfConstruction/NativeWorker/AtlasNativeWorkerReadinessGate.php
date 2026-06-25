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
