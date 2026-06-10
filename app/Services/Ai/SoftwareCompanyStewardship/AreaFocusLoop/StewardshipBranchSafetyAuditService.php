<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-773 · Stewardship Branch Safety Audit.
 *
 * Read-only preflight for the 24/7 stewardship loop. It audits local cycle
 * branches before AP-772 queues them, reusing AP-769 merge governance and
 * AP-770 lifecycle registry data instead of inventing another branch system.
 */
final class StewardshipBranchSafetyAuditService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.branch_safety_audit.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.branch_safety_audit_record.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipBranchLifecycleRegistryService $lifecycleRegistry,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->mergeGovernor->setStorageRootForTesting($dir !== null ? $dir.'/merge_governor' : null);
        $this->lifecycleRegistry->setStorageRootForTesting($dir !== null ? $dir.'/branch_lifecycle_registry' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/branch_safety_audit')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/branch_safety_audit';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::areaRefToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function audit(array $input): array
    {
        $areaId = AreaFocusSlugNormalizer::areaRefToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $repoRoot = AreaFocusLoopPayloadNormalizer::repoRoot($input);
        if ($repoRoot === '' || ! is_dir($repoRoot.'/.git')) {
            return $this->blocked($areaId, 'repo_root_not_git_repository', 'AP-773 requires a local git repository root.');
        }

        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $branchRefs = $this->branchRefs($input, $repoRoot);
        if ($branchRefs === []) {
            return $this->blocked($areaId, 'no_cycle_branches_found', 'No stewardship branch refs were supplied or discovered.');
        }

        $lifecycle = $this->lifecycleRegistry->listRecords($areaId);
        $activeLifecycle = $this->activeLifecycleByBranch((array) ($lifecycle['records'] ?? []));
        $items = [];
        foreach ($branchRefs as $branchRef) {
            $governance = $this->mergeGovernor->evaluate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'branch_ref' => $branchRef,
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            ]);
            $items[] = $this->branchItem($branchRef, $governance, $activeLifecycle[$branchRef] ?? null);
        }

        $summary = $this->summary($items);
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-773',
            'status' => self::STATUS_READY,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-770', 'AP-772', 'AP-773'],
            'audit_id' => 'bsa_'.substr(MissionCanonicalHash::sha256([$areaId, $repoRoot, $baseRef, $branchRefs]), 0, 18),
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
            ],
            'branch_count' => count($branchRefs),
            'branches' => $items,
            'queue_ready_branch_refs' => array_values(array_map(
                static fn (array $item): string => (string) ($item['branch_ref'] ?? ''),
                array_filter($items, static fn (array $item): bool => (bool) ($item['queue_ready'] ?? false)),
            )),
            'blocked_branch_refs' => array_values(array_map(
                static fn (array $item): string => (string) ($item['branch_ref'] ?? ''),
                array_filter($items, static fn (array $item): bool => (string) ($item['safety_state'] ?? '') === 'blocked'),
            )),
            'summary' => $summary,
            'next_actions' => $this->nextActions($summary),
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'cleanup_performed' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'feeds_ap772_queue_only_with_queue_ready_branches' => true,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $payload['audit_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, (bool) ($input['record_audit'] ?? false));
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $areaId = AreaFocusSlugNormalizer::areaRefToken($areaId ?: self::DEFAULT_AREA_ID, self::DEFAULT_AREA_ID);
        $records = AreaFocusJsonlReader::rowsWithSchemaVersion($this->recordPath($areaId), self::RECORD_SCHEMA);

        return [
            'schema_version' => 'atlas.software_company_stewardship.branch_safety_audit_records.v1',
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-773',
            'area_id' => $areaId,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $lifecycle
     * @return array<string,mixed>
     */
    private function branchItem(string $branchRef, array $governance, ?array $lifecycle): array
    {
        $blockers = AreaFocusStringListNormalizer::uniqueTruthyStringifiedValues($governance['blockers'] ?? []);
        $governanceStatus = (string) ($governance['status'] ?? 'unknown');
        $graphShape = (string) data_get($governance, 'gitkraken_review_surface.graph_shape', '');
        $autoEligible = (bool) data_get($governance, 'auto_merge_policy.eligible', false);
        $classification = (string) data_get($governance, 'classification.kind', 'unknown');
        $activeLifecycle = $lifecycle !== null;

        if (! $activeLifecycle && ! str_contains($branchRef, '/true-cycle-live-')) {
            $blockers[] = 'missing_active_lifecycle_registry_record';
        }

        $alreadyMerged = in_array('branch_already_merged_or_ancestor_of_base', $blockers, true);
        $conflicted = in_array('merge_conflict_detected', $blockers, true);
        $stale = in_array('branch_not_rebased_on_current_base', $blockers, true) || $graphShape === 'diverged_or_stale_branch';
        $queueReady = $blockers === [] && ! $alreadyMerged && ! $conflicted && ! $stale;

        return [
            'branch_ref' => $branchRef,
            'safety_state' => $queueReady ? 'queue_ready' : 'blocked',
            'queue_ready' => $queueReady,
            'recommended_queue_action' => $queueReady
                ? ($autoEligible ? 'ap772_auto_merge_candidate_after_live_recheck' : 'ap772_review_queue_candidate')
                : 'do_not_queue_until_repaired_or_released',
            'risk_class' => $this->riskClass($blockers, $classification),
            'governance_status' => $governanceStatus,
            'classification' => $classification,
            'auto_merge_eligible' => $autoEligible,
            'graph_shape' => $graphShape,
            'reviewable_commit_count' => (int) data_get($governance, 'gitkraken_review_surface.reviewable_commit_count', 0),
            'changed_file_count' => count((array) data_get($governance, 'gitkraken_review_surface.changed_files', [])),
            'blockers' => $blockers,
            'lifecycle_registry' => [
                'active_record_found' => $activeLifecycle,
                'registry_id' => (string) ($lifecycle['registry_id'] ?? ''),
                'state' => (string) data_get($lifecycle, 'lifecycle.state', ''),
                'sandbox_id' => (string) data_get($lifecycle, 'source_refs.sandbox_id', ''),
            ],
            'gitkraken_review_surface' => (array) ($governance['gitkraken_review_surface'] ?? []),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,array<string,mixed>>
     */
    private function activeLifecycleByBranch(array $records): array
    {
        $active = [];
        foreach ($records as $record) {
            if (! (bool) data_get($record, 'lifecycle.active', false)) {
                continue;
            }
            $branch = (string) data_get($record, 'branch_identity.branch_name', '');
            if ($branch !== '') {
                $active[$branch] = $record;
            }
        }

        return $active;
    }

    private function riskClass(array $blockers, string $classification): string
    {
        if (array_intersect($blockers, ['merge_conflict_detected', 'branch_not_rebased_on_current_base'])) {
            return 'p0_blocked_conflict_or_stale';
        }
        if (in_array('missing_active_lifecycle_registry_record', $blockers, true)) {
            return 'p1_orphaned_branch';
        }
        if ($blockers !== []) {
            return 'p1_blocked';
        }
        if ($classification === 'code_or_mixed') {
            return 'p2_review_required_code_or_mixed';
        }

        return 'p3_low_risk_queue_candidate';
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        return [
            'queue_ready' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['queue_ready'] ?? false))),
            'blocked' => count(array_filter($items, static fn (array $item): bool => (string) ($item['safety_state'] ?? '') === 'blocked')),
            'auto_merge_candidates' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['queue_ready'] ?? false) && (bool) ($item['auto_merge_eligible'] ?? false))),
            'review_queue_candidates' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['queue_ready'] ?? false) && ! (bool) ($item['auto_merge_eligible'] ?? false))),
            'orphaned' => count(array_filter($items, static fn (array $item): bool => in_array('missing_active_lifecycle_registry_record', (array) ($item['blockers'] ?? []), true))),
            'stale_or_conflicted' => count(array_filter($items, static fn (array $item): bool => (bool) array_intersect(['merge_conflict_detected', 'branch_not_rebased_on_current_base'], (array) ($item['blockers'] ?? [])))),
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @return list<string>
     */
    private function nextActions(array $summary): array
    {
        if (($summary['queue_ready'] ?? 0) > 0) {
            return ['Pass queue_ready_branch_refs into AP-772 merge-queue; blocked branches must be repaired, rebased or released first.'];
        }

        return ['Do not run AP-772 with this branch set; repair stale/conflicted/orphaned branches first.'];
    }

    /**
     * @return list<string>
     */
    private function branchRefs(array $input, string $repoRoot): array
    {
        $refs = AreaFocusBranchRefNormalizer::fromInput($input);
        if ($refs === []) {
            $prefix = trim((string) ($input['branch_prefix'] ?? 'atlas/area-focus/'));
            $refs = AreaFocusBranchRefNormalizer::localBranches($repoRoot, $prefix);
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        return AreaFocusAppendOnlyJsonlRecorder::maybeRecord(
            $payload,
            $record,
            $this->recordPath($areaId),
            self::RECORD_SCHEMA,
            AreaFocusUtcClock::atomNow(),
            'audit_storage_status',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-773',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'read_only' => true,
                'branch_created' => false,
                'merge_performed' => false,
                'deploys' => false,
                'touches_secrets' => false,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['audit_hash'], $copy['generated_at'], $copy['recorded_at'], $copy['audit_storage_status']);

        return $copy;
    }
}
