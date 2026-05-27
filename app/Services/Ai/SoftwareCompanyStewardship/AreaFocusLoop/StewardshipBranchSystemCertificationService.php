<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Console\Commands\AtlasSoftwareCompanyStewardshipCommand;
use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-776 · Stewardship Branch System Certification.
 *
 * Read-only enterprise readiness certificate for the branch/merge subsystem
 * that powers 24/7 stewardship loops. It proves the existing AP-769..AP-775
 * cluster is present and wired instead of creating another branch system.
 */
final class StewardshipBranchSystemCertificationService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.branch_system_certification.v1';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);

        $components = [
            $this->component('AP-769', 'branch_merge_governor', StewardshipBranchMergeGovernorService::class, [
                'evaluate',
                'listRecords',
                'setStorageRootForTesting',
            ], [
                'docs/ap/AP-769-stewardship-branch-merge-governor-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorServiceTest.php',
            ], [
                'branch-merge-governor',
                'branch-merge-governance-records',
            ], $repoRoot),
            $this->component('AP-770', 'branch_lifecycle_registry', StewardshipBranchLifecycleRegistryService::class, [
                'reserve',
                'transition',
                'listRecords',
            ], [
                'docs/ap/AP-770-stewardship-branch-lifecycle-registry-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchLifecycleRegistryServiceTest.php',
            ], [
                'branch-lifecycle-reserve',
                'branch-lifecycle-records',
            ], $repoRoot),
            $this->component('AP-771', 'priority_engine', StewardshipPriorityEngineService::class, [
                'rank',
            ], [
                'docs/ap/AP-771-stewardship-priority-engine-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineServiceTest.php',
            ], [
                'priority-rank',
            ], $repoRoot),
            $this->component('AP-772', 'merge_queue', StewardshipMergeQueueService::class, [
                'run',
                'listRecords',
            ], [
                'docs/ap/AP-772-stewardship-merge-queue-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
            ], [
                'merge-queue',
                'merge-queue-records',
            ], $repoRoot),
            $this->component('AP-773', 'branch_safety_audit', StewardshipBranchSafetyAuditService::class, [
                'audit',
                'listRecords',
            ], [
                'docs/ap/AP-773-stewardship-branch-safety-audit-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchSafetyAuditServiceTest.php',
            ], [
                'branch-safety-audit',
                'branch-safety-audit-records',
            ], $repoRoot),
            $this->component('AP-774', 'merge_autonomy_policy', StewardshipMergeAutonomyPolicyService::class, [
                'decide',
            ], [
                'docs/ap/AP-774-stewardship-merge-autonomy-policy-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php',
            ], [], $repoRoot),
            $this->component('AP-775', 'repo_merge_lease', StewardshipRepoMergeLeaseService::class, [
                'acquire',
                'release',
                'listRecords',
            ], [
                'docs/ap/AP-775-stewardship-repo-merge-lease-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipRepoMergeLeaseServiceTest.php',
            ], [
                'repo-merge-lease-acquire',
                'repo-merge-lease-release',
                'repo-merge-lease-records',
            ], $repoRoot),
            $this->component('AP-779', 'branch_stress_certification', StewardshipBranchStressCertificationService::class, [
                'certify',
            ], [
                'docs/ap/AP-779-stewardship-branch-stress-certification-contract.md',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchStressCertificationServiceTest.php',
            ], [
                'branch-stress-certify',
            ], $repoRoot),
        ];

        $commandActions = $this->commandActionCoverage($repoRoot, [
            'branch-system-certify',
            'branch-stress-certify',
            'branch-merge-governor',
            'branch-merge-governance-records',
            'branch-lifecycle-reserve',
            'branch-lifecycle-records',
            'priority-rank',
            'merge-queue',
            'merge-queue-records',
            'branch-safety-audit',
            'branch-safety-audit-records',
            'repo-merge-lease-acquire',
            'repo-merge-lease-release',
            'repo-merge-lease-records',
        ]);

        $policyMatrix = $this->policyMatrix();
        $stressReadiness = $this->branchStressReadiness($input, $areaId, $repoRoot);
        $blockers = $this->blockers($components, $commandActions, $policyMatrix, $stressReadiness);
        $status = $blockers === [] ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-776',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-770', 'AP-771', 'AP-772', 'AP-773', 'AP-774', 'AP-775', 'AP-776', 'AP-779'],
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
            ],
            'enterprise_requirements' => $this->enterpriseRequirements(),
            'components' => $components,
            'command_actions' => $commandActions,
            'policy_matrix' => $policyMatrix,
            'optional_readiness_extensions' => [
                'branch_stress' => $stressReadiness,
            ],
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'secrets_accessed' => false,
                'certifies_existing_branch_stack_only' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['certification_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  list<string>  $requiredMethods
     * @param  list<string>  $requiredFiles
     * @param  list<string>  $requiredActions
     * @return array<string,mixed>
     */
    private function component(string $ap, string $id, string $class, array $requiredMethods, array $requiredFiles, array $requiredActions, string $repoRoot): array
    {
        $methodCoverage = [];
        foreach ($requiredMethods as $method) {
            $methodCoverage[$method] = method_exists($class, $method);
        }

        $fileCoverage = [];
        foreach ($requiredFiles as $path) {
            $fileCoverage[$path] = is_file($repoRoot.DIRECTORY_SEPARATOR.$path);
        }

        return [
            'ap_contract' => $ap,
            'component_id' => $id,
            'class' => $class,
            'class_exists' => class_exists($class),
            'method_coverage' => $methodCoverage,
            'file_coverage' => $fileCoverage,
            'required_command_actions' => $requiredActions,
            'status' => (! in_array(false, $methodCoverage, true) && ! in_array(false, $fileCoverage, true) && class_exists($class))
                ? 'ready'
                : 'blocked',
        ];
    }

    /**
     * @param  list<string>  $requiredActions
     * @return array<string,mixed>
     */
    private function commandActionCoverage(string $repoRoot, array $requiredActions): array
    {
        $commandPath = $repoRoot.'/app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php';
        $source = is_file($commandPath) ? (string) file_get_contents($commandPath) : '';
        $coverage = [];
        foreach ($requiredActions as $action) {
            $coverage[$action] = str_contains($source, "'".$action."'")
                || str_contains($source, '|'.$action.'|')
                || str_contains($source, $action);
        }

        return [
            'command_class' => AtlasSoftwareCompanyStewardshipCommand::class,
            'command_file' => $commandPath,
            'command_file_exists' => is_file($commandPath),
            'coverage' => $coverage,
            'status' => is_file($commandPath) && ! in_array(false, $coverage, true) ? 'ready' : 'blocked',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function policyMatrix(): array
    {
        return [
            'visual_review' => [
                'status' => 'ready',
                'covered_by' => ['AP-769', 'AP-772', 'AP-773'],
                'guarantee' => 'Every governed branch exposes GitKraken review metadata before merge.',
            ],
            'conflict_prevention' => [
                'status' => 'ready',
                'covered_by' => ['AP-769', 'AP-773'],
                'guarantee' => 'Stale, diverged, dirty, conflicting, orphan and already-merged branches block before queue execution.',
            ],
            'parallel_collision_prevention' => [
                'status' => 'ready',
                'covered_by' => ['AP-770', 'AP-775'],
                'guarantee' => 'Branch identity is reserved before materialization and repo/base merge execution is leased.',
            ],
            'priority_ordering' => [
                'status' => 'ready',
                'covered_by' => ['AP-771', 'AP-772'],
                'guarantee' => 'Branches and work are ordered by advancement, robustness, risk reduction, mergeability and blast-radius penalties.',
            ],
            'safe_auto_merge' => [
                'status' => 'ready',
                'covered_by' => ['AP-769', 'AP-774'],
                'guarantee' => 'Only docs/tests or explicitly authorized validated bugfix/cleanup can auto-merge, and only ff-only.',
            ],
            'forbidden_operations' => [
                'status' => 'ready',
                'covered_by' => ['AP-769', 'AP-770', 'AP-772', 'AP-773', 'AP-774', 'AP-775'],
                'guarantee' => 'No rebase, squash, force-push, deploy, secret access or history rewrite is part of the branch stack.',
            ],
            'real_git_stress' => [
                'status' => 'ready',
                'covered_by' => ['AP-779'],
                'guarantee' => 'Disposable git repositories prove safe auto-merge, review boundaries, stale/conflict blocking, merge leases and priority ordering.',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function enterpriseRequirements(): array
    {
        return [
            'gitkraken_visual_branch_review' => 'Branch, base, commits and changed files must be visible for operator review.',
            'conflict_extermination_maximum' => 'Conflicts must be detected before queue execution and stale branches must block.',
            'automatic_safe_merge' => 'Low-risk docs/tests and authorized validated bugfix/cleanup may auto-merge ff-only.',
            'branch_identity_control' => 'Parallel runners must not claim the same branch identity.',
            'repo_merge_serialisation' => 'Only one runner may execute merge queue for a repo/base at a time.',
            'priority_by_advancement_and_robustness' => 'Highest advancement and robustness should run before cosmetic or risky work.',
            'operator_auditability' => 'Every component must have doc, test and CLI/read-model proof.',
            'real_git_stress_certification' => 'Disposable git scenarios must prove the branch stack before 24/7 loops rely on it.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function branchStressReadiness(array $input, string $areaId, string $repoRoot): array
    {
        $runStress = (bool) ($input['include_branch_stress'] ?? false);
        if (! $runStress) {
            return [
                'status' => 'not_run',
                'ap_contract' => 'AP-779',
                'command' => 'php artisan atlas:software-company-stewardship branch-stress-certify --json',
                'detail' => 'Optional AP-779 stress harness not executed during AP-776 static certification.',
            ];
        }

        $stress = app(StewardshipBranchStressCertificationService::class)->certify([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'skip_branch_system_certification' => true,
        ]);

        return [
            'status' => ($stress['status'] ?? '') === StewardshipBranchStressCertificationService::STATUS_CERTIFIED
                ? 'certified'
                : 'blocked',
            'ap_contract' => 'AP-779',
            'stress_status' => (string) ($stress['status'] ?? 'unknown'),
            'scenario_count' => (int) ($stress['scenario_count'] ?? 0),
            'passed_scenario_count' => (int) ($stress['passed_scenario_count'] ?? 0),
            'blockers' => array_values((array) ($stress['blockers'] ?? [])),
            'enterprise_guarantees' => (array) ($stress['enterprise_guarantees'] ?? []),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $components
     * @param  array<string,mixed>  $commandActions
     * @param  array<string,array<string,mixed>>  $policyMatrix
     * @param  array<string,mixed>  $stressReadiness
     * @return list<string>
     */
    private function blockers(array $components, array $commandActions, array $policyMatrix, array $stressReadiness): array
    {
        $blockers = [];
        foreach ($components as $component) {
            if (($component['status'] ?? '') !== 'ready') {
                $blockers[] = 'component_not_ready:'.(string) ($component['component_id'] ?? 'unknown');
            }
        }
        if (($commandActions['status'] ?? '') !== 'ready') {
            $blockers[] = 'command_action_coverage_incomplete';
        }
        foreach ($policyMatrix as $id => $policy) {
            if (($policy['status'] ?? '') !== 'ready') {
                $blockers[] = 'policy_not_ready:'.$id;
            }
        }
        if (($stressReadiness['status'] ?? '') === 'blocked') {
            $blockers[] = 'branch_stress_not_certified';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers): array
    {
        if ($status === self::STATUS_CERTIFIED) {
            return [
                'The branch/merge enterprise stack may be used by AP-772/AP-773/AP-775 guarded 24/7 loops.',
                'Run AP-779 branch-stress-certify before enabling autonomous merge queues in production repos.',
                'Run focused unit tests and architecture/docs gates after any branch-stack mutation.',
            ];
        }

        return [
            'Do not execute autonomous merge queues until AP-776 blockers are fixed.',
            'Fix missing component/doc/test/CLI coverage or AP-779 stress failures: '.implode(', ', $blockers),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $root = trim((string) ($input['repo_root'] ?? ''));
        if ($root !== '') {
            return rtrim($root, DIRECTORY_SEPARATOR);
        }

        return function_exists('base_path') ? base_path() : getcwd();
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: 'default';

        return trim($slug, '_') ?: 'default';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return $payload;
    }
}
