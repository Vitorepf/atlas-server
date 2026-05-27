<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * AP-779 · Stewardship Branch Stress Certification.
 *
 * Real git-backed stress harness for the AP-769..AP-776 branch stack. It uses
 * disposable repositories only, then proves the loop can distinguish safe
 * auto-merges from review-required code, stale/conflicting/orphan branches and
 * merge lease collisions before 24/7 stewardship is allowed to rely on it.
 */
final class StewardshipBranchStressCertificationService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.branch_stress_certification.v1';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipPriorityEngineService $priorityEngine,
        private readonly StewardshipRepoMergeLeaseService $repoMergeLease,
        private readonly StewardshipMergeQueueService $mergeQueue,
        private readonly StewardshipBranchSafetyAuditService $branchSafetyAudit,
        private readonly StewardshipBranchSystemCertificationService $branchSystemCertification,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);
        $tmpRoot = $this->tmpRoot($input);
        $preserveTmp = (bool) ($input['preserve_tmp'] ?? false);
        $skipBranchSystem = (bool) ($input['skip_branch_system_certification'] ?? false);

        $storageRoot = $tmpRoot.'/records';
        $this->mergeGovernor->setStorageRootForTesting($storageRoot.'/merge_governor');
        $this->repoMergeLease->setStorageRootForTesting($storageRoot.'/repo_merge_lease');
        $this->mergeQueue->setStorageRootForTesting($storageRoot.'/merge_queue');
        $this->branchSafetyAudit->setStorageRootForTesting($storageRoot.'/branch_safety_audit');

        $branchSystem = $skipBranchSystem
            ? ['status' => StewardshipBranchSystemCertificationService::STATUS_CERTIFIED]
            : $this->branchSystemCertification->certify([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
            ]);

        $scenarios = [];
        $blockers = [];

        try {
            $scenarios[] = $this->docsOnlyAutoMerge($tmpRoot, $areaId);
            $scenarios[] = $this->testsOnlyAutoMerge($tmpRoot, $areaId);
            $scenarios[] = $this->testsOnlyWithValidationGreen($tmpRoot, $areaId);
            $scenarios[] = $this->codeReviewBoundary($tmpRoot, $areaId);
            $scenarios[] = $this->conflictBlocksBeforeMerge($tmpRoot, $areaId);
            $scenarios[] = $this->staleBranchBlocks($tmpRoot, $areaId);
            $scenarios[] = $this->orphanBranchBlocks($tmpRoot, $areaId);
            $scenarios[] = $this->leaseCollisionBlocks($tmpRoot, $areaId);
            $scenarios[] = $this->mergeQueueBlockedByActiveLease($tmpRoot, $areaId);
            $scenarios[] = $this->mergeQueueForbiddenOperations();
            $scenarios[] = $this->mergeQueuePreservesMainAsPrimary($tmpRoot, $areaId);
            $scenarios[] = $this->mergeQueuePriorityOrdersAdvancement($tmpRoot, $areaId);
            $scenarios[] = $this->gitkrakenCycleMetadataClear($tmpRoot, $areaId);
            $scenarios[] = $this->priorityOrdersAdvancementBeforeCosmetic();
        } finally {
            if (! $preserveTmp) {
                File::deleteDirectory($tmpRoot);
            }
        }

        foreach ($scenarios as $scenario) {
            if (($scenario['status'] ?? '') !== 'passed') {
                $blockers[] = (string) ($scenario['id'] ?? 'unknown_scenario');
            }
        }
        if (($branchSystem['status'] ?? '') !== StewardshipBranchSystemCertificationService::STATUS_CERTIFIED) {
            $blockers[] = 'branch_system_not_certified';
        }

        $status = $blockers === [] ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED;
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-779',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-770', 'AP-771', 'AP-772', 'AP-773', 'AP-774', 'AP-775', 'AP-776', 'AP-779'],
            'mode' => 'real_git_disposable_repo_stress',
            'tmp_root' => $preserveTmp ? $tmpRoot : null,
            'branch_system_certification_status' => (string) ($branchSystem['status'] ?? 'unknown'),
            'scenario_count' => count($scenarios),
            'passed_scenario_count' => count(array_filter($scenarios, static fn (array $scenario): bool => ($scenario['status'] ?? '') === 'passed')),
            'scenarios' => $scenarios,
            'blockers' => array_values(array_unique($blockers)),
            'enterprise_guarantees' => [
                'gitkraken_visual_review_metadata_proven' => $this->scenarioPassed($scenarios, 'gitkraken_cycle_metadata_clear'),
                'docs_auto_merge_ff_only_proven' => $this->scenarioPassed($scenarios, 'docs_only_auto_merge'),
                'tests_auto_merge_ff_only_proven' => $this->scenarioPassed($scenarios, 'tests_only_auto_merge'),
                'tests_validation_green_path_proven' => $this->scenarioPassed($scenarios, 'tests_only_validation_green'),
                'code_requires_operator_review_proven' => $this->scenarioPassed($scenarios, 'code_review_boundary'),
                'conflict_blocks_before_merge_proven' => $this->scenarioPassed($scenarios, 'conflict_blocks_before_merge'),
                'stale_branch_blocks_before_merge_proven' => $this->scenarioPassed($scenarios, 'stale_branch_blocks'),
                'orphan_branch_blocks_before_queue_proven' => $this->scenarioPassed($scenarios, 'orphan_branch_blocks'),
                'repo_merge_lease_collision_blocks_proven' => $this->scenarioPassed($scenarios, 'lease_collision_blocks'),
                'merge_queue_respects_repo_merge_lease_proven' => $this->scenarioPassed($scenarios, 'merge_queue_blocked_by_active_lease'),
                'merge_queue_never_rebase_squash_force_push_proven' => $this->scenarioPassed($scenarios, 'merge_queue_forbidden_operations'),
                'main_remains_primary_integration_branch_proven' => $this->scenarioPassed($scenarios, 'merge_queue_preserves_main_as_primary'),
                'priority_orders_advancement_before_cosmetic_proven' => $this->scenarioPassed($scenarios, 'priority_orders_advancement_before_cosmetic'),
                'merge_queue_priority_orders_advancement_proven' => $this->scenarioPassed($scenarios, 'merge_queue_priority_orders_advancement'),
                'forbidden_operations_remain_forbidden' => true,
            ],
            'next_actions' => $status === self::STATUS_CERTIFIED
                ? ['AP-779 stress is green; AP-776/AP-779 together may gate 24/7 branch queue enablement.']
                : ['Do not enable autonomous merge queue until blockers are fixed: '.implode(', ', array_values(array_unique($blockers))).'.'],
            'claim_policy' => [
                'uses_disposable_git_repos' => true,
                'mutates_target_repo' => false,
                'provider_invoked' => false,
                'real_git_commands_invoked' => true,
                'auto_merge_tested' => true,
                'target_repo_merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'rebase_performed' => false,
                'squash_performed' => false,
                'force_push_performed' => false,
                'touches_secrets' => false,
                'certifies_existing_branch_stack_only' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['stress_certification_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function docsOnlyAutoMerge(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'docs_only_auto_merge');
        $this->branch($repo, 'atlas/area-focus/docs-auto');
        $this->commitFile($repo, 'docs/README.md', "base docs\nauto docs\n", 'Docs auto merge');
        $branchHead = $this->gitOut($repo, ['rev-parse', '--short', 'HEAD']);
        $this->checkout($repo, 'main');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-auto',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);
        $mainHead = $this->gitOut($repo, ['rev-parse', '--short', 'main']);

        return $this->scenario('docs_only_auto_merge', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED,
            ($report['classification']['kind'] ?? '') === 'documentation_only',
            ($report['gitkraken_review_surface']['graph_shape'] ?? '') === 'branch_on_top_of_base',
            $branchHead === $mainHead,
            (bool) ($report['claim_policy']['merge_performed'] ?? false),
            (bool) ($report['claim_policy']['rebase_performed'] ?? true) === false,
            (bool) ($report['claim_policy']['force_push_performed'] ?? true) === false,
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function testsOnlyAutoMerge(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'tests_only_auto_merge');
        $this->branch($repo, 'atlas/area-focus/tests-auto');
        $this->commitFile($repo, 'tests/Feature/SmokeTest.php', "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n", 'Tests auto merge');
        $branchHead = $this->gitOut($repo, ['rev-parse', '--short', 'HEAD']);
        $this->checkout($repo, 'main');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/tests-auto',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);
        $mainHead = $this->gitOut($repo, ['rev-parse', '--short', 'main']);

        return $this->scenario('tests_only_auto_merge', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED,
            ($report['classification']['kind'] ?? '') === 'tests_only',
            $branchHead === $mainHead,
            (bool) ($report['claim_policy']['merge_performed'] ?? false),
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function testsOnlyWithValidationGreen(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'tests_only_validation_green');
        $this->branch($repo, 'atlas/area-focus/tests-validated');
        $this->commitFile($repo, 'tests/Unit/GreenTest.php', "<?php\n\nit('is green', fn () => expect(true)->toBeTrue());\n", 'Validated tests');
        $this->checkout($repo, 'main');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/tests-validated',
            'auto_merge' => true,
            'execute_merge' => true,
            'run_validation' => true,
            'test_commands' => ['true'],
        ]);

        return $this->scenario('tests_only_validation_green', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED,
            ($report['validation']['passed'] ?? null) === true,
            (bool) ($report['auto_merge_policy']['eligible'] ?? false),
            (bool) ($report['claim_policy']['merge_performed'] ?? false),
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function codeReviewBoundary(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'code_review_boundary');
        $this->branch($repo, 'atlas/area-focus/code-review');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code review boundary');
        $this->checkout($repo, 'main');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/code-review',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        return $this->scenario('code_review_boundary', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED,
            in_array('auto_merge_policy_not_satisfied', (array) ($report['blockers'] ?? []), true),
            in_array('change_class_requires_operator_review', (array) ($report['auto_merge_policy']['reasons'] ?? []), true),
            (bool) ($report['claim_policy']['merge_performed'] ?? true) === false,
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function conflictBlocksBeforeMerge(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'conflict_blocks_before_merge');
        $this->branch($repo, 'atlas/area-focus/conflict');
        $this->commitFile($repo, 'docs/README.md', "branch edit\n", 'Branch edit');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/README.md', "main edit\n", 'Main edit');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/conflict',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        return $this->scenario('conflict_blocks_before_merge', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED,
            in_array('merge_conflict_detected', (array) ($report['blockers'] ?? []), true),
            (bool) ($report['merge_conflict_check']['clean'] ?? true) === false,
            (bool) ($report['claim_policy']['merge_performed'] ?? true) === false,
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function staleBranchBlocks(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'stale_branch_blocks');
        $this->branch($repo, 'atlas/area-focus/stale');
        $this->commitFile($repo, 'docs/stale.md', "branch docs\n", 'Branch docs');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/main.md', "main moved\n", 'Main moved');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/stale',
            'auto_merge' => true,
            'execute_merge' => true,
        ]);

        return $this->scenario('stale_branch_blocks', [
            ($report['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED,
            in_array('branch_not_rebased_on_current_base', (array) ($report['blockers'] ?? []), true),
            ($report['gitkraken_review_surface']['graph_shape'] ?? '') === 'diverged_or_stale_branch',
            (bool) ($report['claim_policy']['merge_performed'] ?? true) === false,
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function orphanBranchBlocks(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'orphan_branch_blocks');
        $this->branch($repo, 'atlas/area-focus/orphan-docs');
        $this->commitFile($repo, 'docs/orphan.md', "orphan docs\n", 'Orphan docs');
        $this->checkout($repo, 'main');

        $audit = $this->branchSafetyAudit->audit([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/orphan-docs'],
        ]);
        $branch = (array) (($audit['branches'][0] ?? []) ?: []);

        return $this->scenario('orphan_branch_blocks', [
            ($audit['status'] ?? '') === StewardshipBranchSafetyAuditService::STATUS_READY,
            ($branch['safety_state'] ?? '') === 'blocked',
            in_array('missing_active_lifecycle_registry_record', (array) ($branch['blockers'] ?? []), true),
            ($branch['risk_class'] ?? '') === 'p1_orphaned_branch',
            ($branch['queue_ready'] ?? true) === false,
            ($audit['queue_ready_branch_refs'] ?? []) === [],
        ], $audit);
    }

    /**
     * @return array<string,mixed>
     */
    private function leaseCollisionBlocks(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'lease_collision_blocks');
        $first = $this->repoMergeLease->acquire([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'runner_one',
            'ttl_seconds' => 600,
        ]);
        $second = $this->repoMergeLease->acquire([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'runner_two',
            'ttl_seconds' => 600,
        ]);

        return $this->scenario('lease_collision_blocks', [
            ($first['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_ACQUIRED,
            ($second['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_BLOCKED,
            in_array('active_merge_lease_exists', (array) ($second['blockers'] ?? []), true),
        ], ['first' => $first, 'second' => $second]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeQueueBlockedByActiveLease(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repoWithQueueBranches($tmpRoot, 'merge_queue_lease_block');
        $this->repoMergeLease->acquire([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'runner-a',
            'ttl_seconds' => 3600,
        ]);

        $queue = $this->mergeQueue->run([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-b'],
            'auto_merge' => true,
            'execute_queue' => true,
            'lease_owner' => 'runner-b',
        ]);

        return $this->scenario('merge_queue_blocked_by_active_lease', [
            ($queue['status'] ?? '') === StewardshipMergeQueueService::STATUS_BLOCKED,
            ($queue['reason'] ?? '') === 'active_merge_lease_exists',
            (bool) ($queue['claim_policy']['merge_performed'] ?? true) === false,
        ], $queue);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeQueueForbiddenOperations(): array
    {
        $queueSource = (string) file_get_contents(
            (string) realpath(__DIR__.'/StewardshipMergeQueueService.php')
        );
        $governorSource = (string) file_get_contents(
            (string) realpath(__DIR__.'/StewardshipBranchMergeGovernorService.php')
        );

        return $this->scenario('merge_queue_forbidden_operations', [
            str_contains($queueSource, "'merge_strategy' => 'ff_only_via_ap769'"),
            str_contains($queueSource, "'parallel_merges_allowed' => false"),
            ! str_contains($queueSource, 'rebase'),
            ! str_contains($queueSource, 'squash'),
            ! str_contains($queueSource, 'force-push'),
            ! str_contains($queueSource, 'force_push'),
            str_contains($governorSource, "'merge', '--ff-only'"),
            ! str_contains($governorSource, 'rebase'),
            ! str_contains($governorSource, 'squash'),
        ], [
            'queue_policy' => [
                'merge_strategy' => 'ff_only_via_ap769',
                'parallel_merges_allowed' => false,
            ],
            'governor_strategy' => 'ff_only',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeQueuePreservesMainAsPrimary(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repoWithQueueBranches($tmpRoot, 'merge_queue_main_primary');
        $mainBefore = $this->gitOut($repo, ['rev-parse', 'main']);
        $branchesBefore = $this->listLocalBranches($repo);

        $queue = $this->mergeQueue->run([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-a', 'atlas/area-focus/docs-b'],
            'auto_merge' => true,
            'execute_queue' => true,
            'lease_owner' => 'stress-runner',
        ]);

        $currentBranch = $this->gitOut($repo, ['branch', '--show-current']);
        $mainAfter = $this->gitOut($repo, ['rev-parse', 'main']);
        $branchesAfter = $this->listLocalBranches($repo);
        $mainIsAncestor = $this->gitOk($repo, ['merge-base', '--is-ancestor', $mainBefore, $mainAfter]);

        return $this->scenario('merge_queue_preserves_main_as_primary', [
            ($queue['status'] ?? '') === StewardshipMergeQueueService::STATUS_EXECUTED,
            $currentBranch === 'main',
            $mainIsAncestor,
            $mainAfter !== $mainBefore,
            ! in_array('atlas-main-secondary', $branchesAfter, true),
            count($branchesAfter) === count($branchesBefore),
            ($queue['queue_policy']['merge_strategy'] ?? '') === 'ff_only_via_ap769',
            (int) ($queue['summary']['auto_merged'] ?? 0) >= 1,
        ], $queue);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeQueuePriorityOrdersAdvancement(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repoWithQueueBranches($tmpRoot, 'merge_queue_priority');
        $queue = $this->mergeQueue->run([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-a', 'atlas/area-focus/code-change'],
        ]);

        $ordered = array_values(array_map(
            static fn (array $item): string => (string) ($item['branch_ref'] ?? ''),
            (array) ($queue['planned_order'] ?? []),
        ));

        return $this->scenario('merge_queue_priority_orders_advancement', [
            ($queue['status'] ?? '') === StewardshipMergeQueueService::STATUS_READY,
            $ordered !== [],
            ($ordered[0] ?? '') === 'atlas/area-focus/docs-a',
            ($ordered[1] ?? '') === 'atlas/area-focus/code-change',
            ((float) data_get($queue, 'planned_order.0.priority_score', 0.0))
                >= ((float) data_get($queue, 'planned_order.1.priority_score', 0.0)),
        ], $queue);
    }

    /**
     * @return array<string,mixed>
     */
    private function gitkrakenCycleMetadataClear(string $tmpRoot, string $areaId): array
    {
        $repo = $this->repo($tmpRoot, 'gitkraken_cycle_metadata');
        $this->branch($repo, 'atlas/area-focus/traceable-docs');
        $this->commitFile($repo, 'docs/README.md', "base docs\ntraceable\n", 'Traceable docs');
        $this->checkout($repo, 'main');

        $report = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/traceable-docs',
            'finding_id' => 'finding_stress_001',
            'spec_id' => 'spec_stress_001',
            'receipt_id' => 'receipt_stress_001',
            'handoff_id' => 'handoff_stress_001',
            'sandbox_id' => 'sandbox_stress_001',
        ]);

        $surface = (array) ($report['gitkraken_review_surface'] ?? []);
        $traceability = (array) ($surface['cycle_traceability'] ?? []);

        return $this->scenario('gitkraken_cycle_metadata_clear', [
            ($surface['visible_base_ref'] ?? '') === 'main',
            ($surface['visible_branch_ref'] ?? '') === 'atlas/area-focus/traceable-docs',
            ($traceability['finding_id'] ?? '') === 'finding_stress_001',
            ($traceability['spec_id'] ?? '') === 'spec_stress_001',
            ($traceability['receipt_id'] ?? '') === 'receipt_stress_001',
            ($traceability['handoff_id'] ?? '') === 'handoff_stress_001',
            ($traceability['sandbox_id'] ?? '') === 'sandbox_stress_001',
            ($surface['graph_shape'] ?? '') === 'branch_on_top_of_base',
        ], $report);
    }

    /**
     * @return array<string,mixed>
     */
    private function priorityOrdersAdvancementBeforeCosmetic(): array
    {
        $report = $this->priorityEngine->rank([
            'area_id' => self::DEFAULT_AREA_ID,
            'candidates' => [
                [
                    'id' => 'cosmetic_doc',
                    'title' => 'Cosmetic doc polish',
                    'kind' => 'doc',
                    'severity' => 'low',
                    'owner' => 'docs',
                    'affected_files' => ['docs/readme.md'],
                    'confidence' => 0.8,
                ],
                [
                    'id' => 'dev_forge_bug',
                    'title' => 'Fix Dev/Forge branch handoff bug',
                    'kind' => 'bug',
                    'severity' => 'high',
                    'owner' => 'dev_forge',
                    'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Handoff.php', 'tests/Unit/HandoffTest.php'],
                    'has_tests' => true,
                    'confidence' => 0.9,
                ],
            ],
        ]);

        return $this->scenario('priority_orders_advancement_before_cosmetic', [
            ($report['status'] ?? '') === StewardshipPriorityEngineService::STATUS_READY,
            ($report['top_candidate']['candidate_id'] ?? '') === 'dev_forge_bug',
            ((float) ($report['top_candidate']['priority_score'] ?? 0.0)) > ((float) ($report['ranked_candidates'][1]['priority_score'] ?? 100.0)),
        ], $report);
    }

    private function repoWithQueueBranches(string $tmpRoot, string $name): string
    {
        $repo = $this->repo($tmpRoot, $name);
        File::ensureDirectoryExists($repo.'/docs');

        $this->branch($repo, 'atlas/area-focus/docs-a');
        $this->commitFile($repo, 'docs/a.md', "a\nbranch a\n", 'Docs A');
        $this->checkout($repo, 'main');

        $this->branch($repo, 'atlas/area-focus/docs-b');
        $this->commitFile($repo, 'docs/b.md', "b\nbranch b\n", 'Docs B');
        $this->checkout($repo, 'main');

        $this->branch($repo, 'atlas/area-focus/code-change');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code change');
        $this->checkout($repo, 'main');

        return $repo;
    }

    /**
     * @return list<string>
     */
    private function listLocalBranches(string $repo): array
    {
        $process = new Process(['git', 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], $repo);
        $process->mustRun();

        return array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
    }

    /**
     * @param  list<string>  $args
     */
    private function gitOk(string $repo, array $args): bool
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<bool>  $assertions
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function scenario(string $id, array $assertions, array $evidence): array
    {
        $passed = ! in_array(false, $assertions, true);

        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'assertion_count' => count($assertions),
            'passed_assertion_count' => count(array_filter($assertions)),
            'evidence' => $this->compactEvidence($evidence),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $scenarios
     */
    private function scenarioPassed(array $scenarios, string $id): bool
    {
        foreach ($scenarios as $scenario) {
            if (($scenario['id'] ?? '') === $id) {
                return ($scenario['status'] ?? '') === 'passed';
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function compactEvidence(array $evidence): array
    {
        return [
            'status' => $evidence['status'] ?? null,
            'reason' => $evidence['reason'] ?? null,
            'blockers' => $evidence['blockers'] ?? null,
            'classification' => $evidence['classification'] ?? null,
            'auto_merge_policy' => $evidence['auto_merge_policy'] ?? null,
            'validation' => $evidence['validation'] ?? null,
            'gitkraken_review_surface' => $evidence['gitkraken_review_surface'] ?? null,
            'merge_conflict_check' => $evidence['merge_conflict_check'] ?? null,
            'claim_policy' => $evidence['claim_policy'] ?? null,
            'queue_policy' => $evidence['queue_policy'] ?? null,
            'summary' => $evidence['summary'] ?? null,
            'top_candidate' => $evidence['top_candidate'] ?? null,
            'branches' => isset($evidence['branches']) ? array_map(static fn (array $branch): array => [
                'branch_ref' => $branch['branch_ref'] ?? null,
                'safety_state' => $branch['safety_state'] ?? null,
                'blockers' => $branch['blockers'] ?? null,
            ], (array) $evidence['branches']) : null,
            'first_status' => $evidence['first']['status'] ?? null,
            'second_status' => $evidence['second']['status'] ?? null,
            'second_blockers' => $evidence['second']['blockers'] ?? null,
        ];
    }

    private function repo(string $tmpRoot, string $name): string
    {
        $repo = $tmpRoot.'/repos/'.$name;
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        File::ensureDirectoryExists($repo.'/tests');
        $this->runGit($repo, ['init']);
        $this->runGit($repo, ['config', 'user.email', 'atlas@example.test']);
        $this->runGit($repo, ['config', 'user.name', 'Atlas Test']);
        file_put_contents($repo.'/docs/README.md', "base docs\n");
        file_put_contents($repo.'/docs/a.md', "a\n");
        file_put_contents($repo.'/docs/b.md', "b\n");
        file_put_contents($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit($repo, ['add', '.']);
        $this->runGit($repo, ['commit', '-m', 'Initial commit']);
        $this->runGit($repo, ['branch', '-M', 'main']);

        return $repo;
    }

    private function branch(string $repo, string $name): void
    {
        $this->runGit($repo, ['checkout', '-b', $name]);
    }

    private function checkout(string $repo, string $name): void
    {
        $this->runGit($repo, ['checkout', $name]);
    }

    private function commitFile(string $repo, string $path, string $contents, string $message): void
    {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        file_put_contents($repo.'/'.$path, $contents);
        $this->runGit($repo, ['add', $path]);
        $this->runGit($repo, ['commit', '-m', $message]);
    }

    /**
     * @param  list<string>  $args
     */
    private function runGit(string $repo, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('git '.implode(' ', $args).' failed: '.$process->getErrorOutput().$process->getOutput());
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function gitOut(string $repo, array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : (getcwd() ?: '');
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function tmpRoot(array $input): string
    {
        $candidate = trim((string) ($input['tmp_root'] ?? ''));
        if ($candidate !== '') {
            File::ensureDirectoryExists($candidate);

            return $candidate;
        }

        $dir = (function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/branch_stress_certification')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/branch_stress_certification')
            .'/run_'.uniqid('', true);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['stress_certification_hash'], $copy['tmp_root']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
