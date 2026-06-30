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
 *
 * HARDENING (cross-project leak prevention): the lane manifest may carry `context_freshness` (the
 * pass-through verdict from {@see AtlasProjectLaneContextFreshnessGate}) and `namespace` (from
 * {@see AtlasProjectLaneQueueNamespacePolicy}). The router REFUSES to route when:
 *   - context_freshness.conformant === false (stale docs/code-index/context-pack/queue/ledger evidence)
 *   - the candidate's task_packet_id already carries a DIFFERENT lane namespace prefix
 *   - acceptance_criteria contains no runnable signal (test/artisan/php invocation)
 *   - the candidate declares a provider/human steady-state dependency
 *     (requires_operator/requires_human/requires_external_provider, or
 *     steady_state_runtime_owner not atlas_native)
 */
final class AtlasProjectLaneTaskFabricRouter
{
    public const SCHEMA = 'atlas.multiproject.fabric_packet.v1';

    public const ISOLATION = 'shared_local_main_with_scope_lock';

    public const SIMPLICITY = 'atlas_native';

    private const RUNNABLE_ACCEPTANCE_TOKENS = ['test', 'artisan', 'php ', 'phpunit', 'runs ', 'executes '];

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

        // Stale context: refuse before doing any other work.
        $contextFreshness = is_array($lane['context_freshness'] ?? null) ? $lane['context_freshness'] : null;
        if ($contextFreshness !== null && ! (bool) ($contextFreshness['conformant'] ?? false)) {
            throw new RuntimeException('atlas_project_lane_task_fabric:stale_context:'.implode(',', (array) ($contextFreshness['blockers'] ?? [])));
        }

        // Namespace mismatch: a candidate already carrying ANOTHER lane's namespace prefix is leakage.
        $laneNamespace = (string) ($lane['namespace'] ?? '');
        $rawCandidateId = trim((string) ($candidate['task_packet_id'] ?? ''));
        if ($laneNamespace !== '' && str_starts_with($rawCandidateId, 'lane.')) {
            $colon = strpos($rawCandidateId, ':');
            $prefix = $colon === false ? $rawCandidateId : substr($rawCandidateId, 0, $colon);
            if ($prefix !== $laneNamespace) {
                throw new RuntimeException('atlas_project_lane_task_fabric:namespace_mismatch:'.$prefix.'!='.$laneNamespace);
            }
        }

        // Provider/human steady-state dependency markers are never allowed in a routed packet.
        if ((bool) ($candidate['requires_operator'] ?? false)
            || (bool) ($candidate['requires_human'] ?? false)
            || (bool) ($candidate['requires_external_provider'] ?? false)) {
            throw new RuntimeException('atlas_project_lane_task_fabric:provider_or_human_dependency_marker_present');
        }
        $steadyStateOwner = (string) ($candidate['steady_state_runtime_owner'] ?? self::SIMPLICITY);
        if ($steadyStateOwner !== '' && $steadyStateOwner !== self::SIMPLICITY) {
            throw new RuntimeException('atlas_project_lane_task_fabric:non_atlas_native_steady_state_owner:'.$steadyStateOwner);
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
        $hasRunnableAcceptance = $this->hasRunnableSignal($acceptance);
        if (! $hasRunnableAcceptance) {
            throw new RuntimeException('atlas_project_lane_task_fabric:acceptance_criteria_not_runnable');
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
                'queue_namespace'         => $laneNamespace !== '' ? $laneNamespace : 'queue:'.$projectId,
                'context_freshness'       => $contextFreshness ?? ['conformant' => true, 'blockers' => []],
                'runnable_acceptance'     => $hasRunnableAcceptance,
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

    /** @param  list<mixed>  $acceptance */
    private function hasRunnableSignal(array $acceptance): bool
    {
        foreach ($acceptance as $criterion) {
            $haystack = strtolower((string) $criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_TOKENS as $token) {
                if (str_contains($haystack, $token)) {
                    return true;
                }
            }
        }

        return false;
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
