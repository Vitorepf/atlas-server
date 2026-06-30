<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure registry that turns an admitted project stewardship lane (Atlas-server or external project)
 * into a runtime daemon instance descriptor.
 *
 * Each descriptor carries everything a separate daemon lane needs to run in isolation:
 *   lane_id / project_id / lane_type / queue_namespace / allowed_roots / forbidden_roots /
 *   daemon_tick_command / scheduler_policy / verification_hooks / release_hooks /
 *   knowledge_sync_hooks / isolation_evidence_refs.
 *
 * Rejects any lane with operator/human/provider dependency in steady-state fields, missing queue
 * namespace, missing allowed roots, roots that cross the lane boundary, or a missing/non-matching
 * scheduler manifest. Preserves `shared_local_main_with_scope_lock` topology — never silently
 * upgrades to worktree/sandbox/branch.
 *
 * Pure: no I/O, no process, no provider, no git, no scheduler/queue side-effect.
 */
final class AtlasProjectLaneRuntimeInstanceRegistry
{
    public const SCHEMA = 'atlas.project_lane.runtime_instance_registry.v1';

    public const ALLOWED_LANE_TYPES = ['atlas_internal', 'external_project'];

    public const REQUIRED_EXECUTION_TOPOLOGY = 'shared_local_main_with_scope_lock';

    public const FORBIDDEN_STEADY_STATE_DEPENDENCIES = [
        'operator',
        'human',
        'external_provider',
        'claude_code',
        'codex',
        'cursor',
        'git',
        'network',
        'unrestricted_shell',
    ];

    /**
     * @param  array<string,mixed>  $laneFacts        single lane OR {lanes: [...]}
     * @param  array<string,mixed>  $daemonManifest   output of {@see AtlasSelfConstructionRuntimeSchedulerManifest::manifest}
     * @return array<string,mixed>
     */
    public function build(array $laneFacts, array $daemonManifest): array
    {
        $lanes = $this->extractLanes($laneFacts);
        $manifestSchema = (string) ($daemonManifest['schema_version'] ?? '');
        $tickCommand = (string) ($daemonManifest['tick_command'] ?? '');

        $manifestBlockers = [];
        if ($manifestSchema !== 'atlas.self_construction.runtime_scheduler_manifest.v1') {
            $manifestBlockers[] = 'scheduler_manifest_schema_mismatch';
        }
        if ($tickCommand === '') {
            $manifestBlockers[] = 'scheduler_manifest_tick_command_missing';
        }

        $instances = [];
        $blockers = $manifestBlockers;

        // Cross-lane duplicate namespace check.
        $seenNamespaces = [];
        foreach ($lanes as $lane) {
            $ns = (string) ($lane['queue_namespace'] ?? '');
            if ($ns !== '') {
                if (isset($seenNamespaces[$ns])) {
                    $blockers[] = 'duplicate_queue_namespace:'.$ns;
                }
                $seenNamespaces[$ns] = true;
            }
        }

        foreach ($lanes as $lane) {
            $verdict = $this->buildInstance($lane, $daemonManifest);
            if ($verdict['blockers'] !== []) {
                $blockers = array_merge($blockers, array_map(
                    static fn (string $b): string => (string) ($lane['lane_id'] ?? '').':'.$b,
                    $verdict['blockers'],
                ));

                continue;
            }
            $instances[] = $verdict['instance'];
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => $blockers === [] ? 'ok' : 'rejected',
            'instances' => $instances,
            'blockers' => array_values(array_unique($blockers)),
        ];
        $payload['registry_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $laneFacts
     * @return list<array<string,mixed>>
     */
    private function extractLanes(array $laneFacts): array
    {
        if (isset($laneFacts['lanes']) && is_array($laneFacts['lanes'])) {
            return array_values($laneFacts['lanes']);
        }

        return [$laneFacts];
    }

    /**
     * @param  array<string,mixed>  $lane
     * @param  array<string,mixed>  $daemonManifest
     * @return array{instance:array<string,mixed>, blockers:list<string>}
     */
    private function buildInstance(array $lane, array $daemonManifest): array
    {
        $blockers = [];

        $laneId = (string) ($lane['lane_id'] ?? '');
        if ($laneId === '') {
            $blockers[] = 'lane_id_missing';
        }
        $projectId = (string) ($lane['project_id'] ?? '');
        if ($projectId === '') {
            $blockers[] = 'project_id_missing';
        }
        $laneType = (string) ($lane['lane_type'] ?? '');
        if (! in_array($laneType, self::ALLOWED_LANE_TYPES, true)) {
            $blockers[] = 'lane_type_invalid:'.$laneType;
        }
        $namespace = (string) ($lane['queue_namespace'] ?? '');
        if ($namespace === '') {
            $blockers[] = 'queue_namespace_missing';
        }

        $allowedRoots = array_values(array_map('strval', (array) ($lane['allowed_roots'] ?? [])));
        $forbiddenRoots = array_values(array_map('strval', (array) ($lane['forbidden_roots'] ?? [])));
        if ($allowedRoots === []) {
            $blockers[] = 'allowed_roots_missing';
        }

        $laneBoundary = (string) ($lane['lane_boundary_root'] ?? '');
        if ($laneBoundary !== '') {
            foreach ($allowedRoots as $root) {
                if (! $this->rootInside($root, $laneBoundary)) {
                    $blockers[] = 'allowed_root_outside_lane_boundary:'.$root;
                }
            }
        }

        // Forbidden roots must not overlap allowed roots.
        foreach ($forbiddenRoots as $fr) {
            foreach ($allowedRoots as $ar) {
                if ($fr !== '' && $ar !== '' && ($this->rootInside($fr, $ar) || $this->rootInside($ar, $fr))) {
                    $blockers[] = 'forbidden_root_overlaps_allowed:'.$fr;
                    break;
                }
            }
        }

        // runtime_owner and steady_state_owner must be atlas_native or atlas_server.
        $allowedOwners = ['atlas_native', 'atlas_server'];
        $runtimeOwner = (string) ($lane['runtime_owner'] ?? 'atlas_native');
        $steadyStateOwner = (string) ($lane['steady_state_owner'] ?? 'atlas_server');
        if (! in_array($runtimeOwner, $allowedOwners, true)) {
            $blockers[] = 'runtime_owner_not_atlas:'.$runtimeOwner;
        }
        if (! in_array($steadyStateOwner, $allowedOwners, true)) {
            $blockers[] = 'steady_state_owner_not_atlas:'.$steadyStateOwner;
        }

        $topology = (string) ($lane['execution_topology'] ?? self::REQUIRED_EXECUTION_TOPOLOGY);
        if ($topology !== self::REQUIRED_EXECUTION_TOPOLOGY) {
            $blockers[] = 'execution_topology_unexpected:'.$topology;
        }

        $steadyState = (array) ($lane['steady_state_dependencies'] ?? []);
        foreach ($steadyState as $dep) {
            if (in_array((string) $dep, self::FORBIDDEN_STEADY_STATE_DEPENDENCIES, true)) {
                $blockers[] = 'steady_state_dependency_refused:'.(string) $dep;
            }
        }

        $instance = [
            'lane_id' => $laneId,
            'project_id' => $projectId,
            'lane_type' => $laneType,
            'queue_namespace' => $namespace,
            'allowed_roots' => $allowedRoots,
            'forbidden_roots' => $forbiddenRoots,
            'execution_topology' => $topology,
            'daemon_tick_command' => (string) ($daemonManifest['tick_command'] ?? ''),
            'scheduler_policy' => [
                'cadence_seconds' => (int) ($daemonManifest['cadence_seconds'] ?? 0),
                'heartbeat_max_age_seconds' => (int) ($daemonManifest['heartbeat_max_age_seconds'] ?? 0),
                'max_runtime_seconds' => (int) ($daemonManifest['max_runtime_seconds'] ?? 0),
                'backoff_policy' => (array) ($daemonManifest['backoff_policy'] ?? []),
                'safety_stop_conditions' => array_values((array) ($daemonManifest['safety_stop_conditions'] ?? [])),
            ],
            'verification_hooks' => array_values((array) ($lane['verification_hooks'] ?? [])),
            'release_hooks' => array_values((array) ($lane['release_hooks'] ?? [])),
            'knowledge_sync_hooks' => array_values((array) ($lane['knowledge_sync_hooks'] ?? [])),
            'isolation_evidence_refs' => array_values((array) ($lane['isolation_evidence_refs'] ?? [])),
        ];

        return ['instance' => $instance, 'blockers' => $blockers];
    }

    private function rootInside(string $root, string $boundary): bool
    {
        $root = trim($root, '/');
        $boundary = trim($boundary, '/');
        if ($boundary === '') {
            return true;
        }

        return $root === $boundary || str_starts_with($root, $boundary.'/');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['registry_hash']);
        ksort($copy);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
