<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\E2E;

/**
 * Pure planner. Turns an admitted project lane + organ-coverage facts into a project-SPECIFIC 24/7
 * stewardship runtime plan that preserves every project boundary.
 *
 * Inputs:
 *   lane      : admitted project-lane manifest (output of AtlasProjectLaneAdmissionPolicy)
 *   coverage  : organ-contract coverage verdict (output of AtlasSelfConstructionOrganContractCoverageGate)
 *
 * Output:
 *   {schema_version, project_id, runtime_plan:{repository_root, mainline_branch, mainline_policy,
 *     forbidden_paths, task_queue_namespace, verification_gates, merge_policy, receipts_path,
 *     knowledge_sync_targets, workspace_topology}, refused:bool, blockers:list<string>}
 *
 * Refuses when:
 *   - lane is not admitted
 *   - lane has no project_id or repo_root
 *   - coverage gate is not fully covered
 *   - any target path in coverage facts attempts to escape repo_root (cross-project mutation)
 */
final class AtlasSelfConstructionStewardshipInstanceRuntimePlan
{
    public const SCHEMA = 'atlas.stewardship.instance_runtime_plan.v1';

    public const ISOLATION = 'shared_local_main_with_scope_lock';

    /**
     * @param  array<string,mixed>  $lane
     * @param  array<string,mixed>  $coverage
     * @return array<string,mixed>
     */
    public function compose(array $lane, array $coverage): array
    {
        $blockers = [];

        $projectId = (string) ($lane['project_id'] ?? '');
        $repoRoot = rtrim((string) ($lane['repo_root'] ?? ''), '/');
        $mainline = (string) ($lane['mainline_branch'] ?? 'main');
        $admitted = (bool) ($lane['admitted'] ?? false);
        $allowedScopes = array_values((array) ($lane['allowed_scope_roots'] ?? []));
        $forbiddenPaths = array_values((array) ($lane['forbidden_paths'] ?? []));
        $mergePolicy = is_array($lane['merge_policy'] ?? null) ? $lane['merge_policy'] : [];
        $verificationCmds = array_values((array) ($lane['verification_commands'] ?? []));
        $knowledgeSync = is_array($lane['knowledge_sync_policy'] ?? null) ? $lane['knowledge_sync_policy'] : [];

        if (! $admitted) {
            $blockers[] = 'lane_not_admitted';
        }
        if ($projectId === '') {
            $blockers[] = 'project_id_missing';
        }
        if ($repoRoot === '') {
            $blockers[] = 'repo_root_missing';
        }
        if (! (bool) ($coverage['fully_covered'] ?? false)) {
            $blockers[] = 'organ_coverage_incomplete';
            foreach ((array) ($coverage['missing_organ'] ?? []) as $m) {
                $blockers[] = 'coverage:missing_organ:'.(string) $m;
            }
        }

        // Cross-project mutation guard — every allowed scope MUST live under repo_root.
        foreach ($allowedScopes as $scope) {
            $scope = (string) $scope;
            if ($scope === '' || str_contains($scope, '..')) {
                $blockers[] = 'cross_project_scope_escape:'.$scope;

                continue;
            }
            if ($scope[0] === '/' && ! str_starts_with($scope, $repoRoot.'/') && $scope !== $repoRoot) {
                $blockers[] = 'cross_project_scope_escape:'.$scope;
            }
        }

        if ($blockers !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'project_id' => $projectId,
                'refused' => true,
                'blockers' => array_values($blockers),
                'runtime_plan' => null,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'project_id' => $projectId,
            'refused' => false,
            'blockers' => [],
            'runtime_plan' => [
                'repository_root' => $repoRoot,
                'mainline_branch' => $mainline,
                'mainline_policy' => $mergePolicy['mode'] ?? 'shared_main_with_scope_lock',
                'forbidden_paths' => $forbiddenPaths,
                'task_queue_namespace' => 'atlas:queue:'.$projectId,
                'verification_gates' => $verificationCmds,
                'merge_policy' => $mergePolicy,
                'receipts_path' => 'storage/atlas/stewardship/'.$projectId.'/receipts.jsonl',
                'knowledge_sync_targets' => array_values((array) ($knowledgeSync['targets'] ?? ['docs', 'code_index', 'context_pack'])),
                'workspace_topology' => self::ISOLATION,
            ],
        ];
    }
}
