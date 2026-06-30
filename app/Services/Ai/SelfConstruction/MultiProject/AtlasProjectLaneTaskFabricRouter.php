<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use RuntimeException;

/**
 * Pure router that turns an admitted project lane manifest + a candidate task spec into a task-fabric
 * packet. Enforces cross-project containment by REFUSING any candidate whose allowed_files or scope_in
 * paths fall outside the lane's allowed_scope_roots.
 *
 * Output packet always carries `workspace_policy.isolation = shared_local_main_with_scope_lock` and
 * `workspace_policy.simplicity = atlas_native`. NEVER calls providers/shells/git/workers/humans.
 */
final class AtlasProjectLaneTaskFabricRouter
{
    public const SCHEMA = 'atlas.multiproject.fabric_packet.v1';

    public const ISOLATION = 'shared_local_main_with_scope_lock';

    public const SIMPLICITY = 'atlas_native';

    /**
     * @param  array<string,mixed>  $lane       admitted lane manifest (project_id, allowed_scope_roots, ...)
     * @param  array<string,mixed>  $candidate  raw candidate task spec
     * @return array<string,mixed>
     */
    public function route(array $lane, array $candidate): array
    {
        if (! (bool) ($lane['admitted'] ?? false)) {
            throw new RuntimeException('atlas_project_lane_task_fabric:lane_not_admitted');
        }

        $projectId = (string) ($lane['project_id'] ?? '');
        if ($projectId === '') {
            throw new RuntimeException('atlas_project_lane_task_fabric:lane_project_id_required');
        }

        $allowedScopeRoots = array_values((array) ($lane['allowed_scope_roots'] ?? []));
        if ($allowedScopeRoots === []) {
            throw new RuntimeException('atlas_project_lane_task_fabric:lane_allowed_scope_roots_required');
        }

        $objective = trim((string) ($candidate['objective'] ?? ''));
        // Deduplicate and sort deterministically before containment checks.
        $allowedFiles = array_values(array_unique(array_values((array) ($candidate['allowed_files'] ?? []))));
        sort($allowedFiles);
        $scopeIn = array_values(array_unique(array_values((array) ($candidate['scope_in'] ?? []))));
        sort($scopeIn);
        $acceptance = array_values((array) ($candidate['acceptance_criteria'] ?? []));
        $evidence = array_values((array) ($candidate['required_evidence'] ?? []));

        if ($objective === '' || $allowedFiles === []) {
            throw new RuntimeException('atlas_project_lane_task_fabric:candidate_must_have_objective_and_allowed_files');
        }
        if ($acceptance === []) {
            throw new RuntimeException('atlas_project_lane_task_fabric:candidate_must_have_runnable_acceptance_criteria');
        }
        if ($evidence === []) {
            throw new RuntimeException('atlas_project_lane_task_fabric:candidate_must_have_required_evidence');
        }

        foreach ([...$allowedFiles, ...$scopeIn] as $path) {
            if (! $this->withinScope((string) $path, $allowedScopeRoots)) {
                throw new RuntimeException('atlas_project_lane_task_fabric:path_escapes_lane_scope:'.$path);
            }
        }

        $rawId = (string) ($candidate['task_packet_id'] ?? '');
        $packetId = str_starts_with($rawId, $projectId.':') ? $rawId : $projectId.':'.($rawId !== '' ? $rawId : substr(hash('sha256', $objective), 0, 12));

        $checkedPaths = array_values(array_unique(array_merge($allowedFiles, $scopeIn)));
        sort($checkedPaths);

        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $packetId,
            'project_id'     => $projectId,
            'objective'      => $objective,
            'allowed_files'  => $allowedFiles,
            'scope_in'       => $scopeIn,
            'acceptance_criteria' => array_values($acceptance),
            'required_evidence'   => array_values($evidence),
            'workspace_policy' => [
                'isolation'          => self::ISOLATION,
                'simplicity'         => self::SIMPLICITY,
                'allowed_scope_roots' => $allowedScopeRoots,
            ],
            'project_lane_proof_contract' => [
                'lane_id'                 => $projectId,
                'isolation_evidence_refs' => $allowedScopeRoots,
                'queue_namespace'         => 'queue:'.$projectId,
                'acceptance_command_hints' => array_values($acceptance),
                'required_evidence'        => array_values($evidence),
                'cross_project_leak_guard' => [
                    'allowed_scope_roots'   => $allowedScopeRoots,
                    'checked_paths'         => $checkedPaths,
                    'all_paths_within_lane' => true,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $allowedScopeRoots
     */
    private function withinScope(string $path, array $allowedScopeRoots): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        // Absolute paths must be a prefix-match against a scope root.
        if ($path[0] === '/') {
            foreach ($allowedScopeRoots as $root) {
                $root = rtrim((string) $root, '/');
                if ($path === $root || str_starts_with($path, $root.'/')) {
                    return true;
                }
            }

            return false;
        }
        // Relative paths must match the suffix of at least one scope root (lane-relative containment).
        foreach ($allowedScopeRoots as $root) {
            $root = rtrim((string) $root, '/');
            $leaf = substr($root, (int) strrpos($root, '/') + 1);
            if ($path === $leaf || str_starts_with($path, $leaf.'/')) {
                return true;
            }
        }

        return false;
    }
}
