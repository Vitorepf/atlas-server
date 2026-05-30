<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-756 · materializes an AP-726 Area Focus branch sandbox.
 *
 * This is the narrow bridge from branch metadata to an isolated git worktree.
 * It requires an explicit operator sandbox receipt and never starts Dev/Forge,
 * invokes providers, applies fixes, merges, deploys, pushes or touches secrets.
 */
final class AreaFocusBranchSandboxMaterializerService implements AreaFocusBranchSandboxMaterializer
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1';

    public const CLEANUP_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_cleanup.v1';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_MATERIALIZED = 'materialized';

    public const STATUS_CLEANED = 'cleaned';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchLifecycleRegistryService $branchLifecycleRegistry,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->branchLifecycleRegistry->setStorageRootForTesting($dir !== null ? $dir.'/branch_lifecycle_registry' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/area_focus_branch_sandboxes')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_branch_sandboxes';
    }

    public function sandboxRecordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function materialize(array $input): array
    {
        $preflight = is_array($input['preflight_report'] ?? null) ? $input['preflight_report'] : [];
        if ($preflight === []) {
            return $this->blocked(self::DEFAULT_AREA_ID, 'preflight_report_required', 'AP-756 requires an AP-726 preflight/handoff report.');
        }

        $areaId = trim((string) ($preflight['area_id'] ?? $input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;
        $receipt = is_array($input['sandbox_receipt'] ?? null) ? $input['sandbox_receipt'] : [];
        $receiptBlock = $this->sandboxReceiptBlocker($receipt);
        if ($receiptBlock !== null) {
            return $this->blocked($areaId, $receiptBlock['reason'], $receiptBlock['detail'], $preflight);
        }

        $targetHash = $this->targetHash($receipt);
        $handoff = $this->matchingReadyHandoff($preflight, $targetHash);
        if ($handoff === null) {
            return $this->blocked($areaId, 'ready_handoff_not_found', 'Sandbox receipt target does not match a ready AP-726 Dev/Forge handoff.', $preflight, [
                'target_handoff_hash' => $targetHash,
                'ready_handoff_hashes' => array_map(static fn (array $h): string => (string) ($h['handoff_hash'] ?? ''), $this->readyHandoffs($preflight)),
            ]);
        }

        $branchPlan = is_array($handoff['branch_plan'] ?? null) ? $handoff['branch_plan'] : [];
        $branchName = $this->branchName($branchPlan);
        $branchBlocker = $this->branchNameBlocker($branchName);
        if ($branchBlocker !== null) {
            return $this->blocked($areaId, $branchBlocker['reason'], $branchBlocker['detail'], $preflight, [
                'target_handoff_hash' => $targetHash,
                'branch_name' => $branchName,
            ]);
        }

        $repoRoot = $this->repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? data_get($branchPlan, 'proposed_base_ref', data_get($branchPlan, 'base_ref_plan', 'HEAD')))) ?: 'HEAD';
        $sandboxId = $this->sandboxId($areaId, $handoff, $receipt, $branchName);
        $worktreePath = $this->worktreePath($sandboxId);
        $materialize = (bool) ($input['materialize_sandbox'] ?? false);
        $record = $materialize || (bool) ($input['record_sandbox'] ?? false);

        $existing = $this->findRecord($this->sandboxRecordPath($areaId), $sandboxId);
        if ($existing !== null) {
            return $this->resumeExistingSandbox($existing, $areaId, $preflight);
        }

        $registry = $this->branchLifecycleRegistry->reserve([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $baseRef,
            'branch_name' => $branchName,
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'handoff_id' => (string) ($handoff['handoff_id'] ?? ''),
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
            'sandbox_id' => $sandboxId,
            'owner' => (string) ($handoff['target_owner'] ?? $handoff['route'] ?? ''),
            'lifecycle_status' => StewardshipBranchLifecycleRegistryService::STATUS_RESERVED,
            'record_branch_registry' => $record,
        ]);
        if (($registry['status'] ?? '') === StewardshipBranchLifecycleRegistryService::STATUS_BLOCKED) {
            return $this->blocked($areaId, (string) ($registry['reason'] ?? 'branch_lifecycle_registry_blocked'), (string) ($registry['detail'] ?? 'AP-770 blocked branch lifecycle reservation.'), $preflight, [
                'sandbox_id' => $sandboxId,
                'branch_lifecycle_registry' => $registry,
            ]);
        }

        $git = $this->gitPreflight($repoRoot, $branchName, $baseRef, $worktreePath);
        if ($git['status'] === self::STATUS_BLOCKED) {
            return $this->blocked($areaId, (string) $git['reason'], (string) $git['detail'], $preflight, [
                'sandbox_id' => $sandboxId,
                'branch_lifecycle_registry' => $registry,
                'git_preflight' => $git,
            ]);
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-756',
            'status' => self::STATUS_PLANNED,
            'mode' => $materialize ? 'materialize_worktree' : 'dry_run_materialization_plan',
            'area_id' => $areaId,
            'sandbox_id' => $sandboxId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-724', 'AP-726', 'AP-747', 'AP-756'],
            'source_refs' => $this->sourceRefs($preflight, $handoff, $receipt),
            'branch_lifecycle_registry' => $this->registrySummary($registry),
            'sandbox_receipt' => $this->receiptSummary($receipt),
            'branch_plan' => $branchPlan,
            'materialization' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
                'base_commit' => (string) ($git['base_commit'] ?? ''),
                'source_branch' => (string) ($git['source_branch'] ?? ''),
                'source_worktree_dirty' => (bool) ($git['source_worktree_dirty'] ?? false),
                'branch_name' => $branchName,
                'worktree_path' => $worktreePath,
                'worktree_path_hash' => hash('sha256', $worktreePath),
                'branch_created' => false,
                'worktree_created' => false,
                'target_repo_mutated' => false,
                'provider_invoked' => false,
                'runtime_execution_started' => false,
            ],
            'record_sandbox_requested' => $record,
            'blockers' => [],
            'next_actions' => $this->nextActions($materialize),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($materialize, false),
        ];

        if ($materialize) {
            $created = $this->runWorktreeAdd($repoRoot, $branchName, $worktreePath, $baseRef);
            if ($created['status'] === self::STATUS_BLOCKED) {
                return $this->blocked($areaId, (string) $created['reason'], (string) $created['detail'], $preflight, [
                    'sandbox_id' => $sandboxId,
                'git_preflight' => $git,
                'branch_lifecycle_registry' => $registry,
                'git_result' => $created,
            ]);
            }

            $payload['status'] = self::STATUS_MATERIALIZED;
            $payload['materialization'] = array_merge($payload['materialization'], [
                'branch_created' => true,
                'worktree_created' => true,
                'target_repo_mutated' => false,
                'created_at' => $this->now(),
                'current_worktree_branch' => (string) ($created['current_worktree_branch'] ?? ''),
            ]);
            $payload['claim_policy'] = $this->claimPolicy(true, true);
        }

        $payload['sandbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $record);
    }

    /**
     * @return array<string,mixed>
     */
    public function listSandboxes(?string $areaId = null): array
    {
        $areas = $areaId !== null && trim($areaId) !== ''
            ? [$areaId]
            : $this->areasWithRecords();

        $records = [];
        $cleanups = [];
        foreach ($areas as $area) {
            $path = $this->sandboxRecordPath($area);
            if (! is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $schema = (string) ($decoded['schema_version'] ?? '');
                if ($schema === self::CLEANUP_SCHEMA) {
                    $sandboxId = (string) ($decoded['sandbox_id'] ?? '');
                    if ($sandboxId !== '') {
                        $cleanups[$sandboxId] = $decoded;
                    }

                    continue;
                }
                if ($schema === self::RECORD_SCHEMA) {
                    $records[] = $decoded;
                }
            }
        }

        foreach ($records as &$record) {
            $sandboxId = (string) ($record['sandbox_id'] ?? '');
            $cleanup = $cleanups[$sandboxId] ?? null;
            $cleaned = $cleanup !== null && (bool) ($cleanup['cleaned'] ?? false);
            $record['lifecycle_state'] = $cleaned ? self::STATUS_CLEANED : (string) ($record['status'] ?? 'unknown');
            if ($cleanup !== null) {
                $record['cleanup'] = [
                    'cleaned' => $cleaned,
                    'worktree_removed' => (bool) data_get($cleanup, 'actions.worktree_removed', false),
                    'branch_deleted' => (bool) data_get($cleanup, 'actions.branch_deleted', false),
                    'cleaned_at' => (string) ($cleanup['recorded_at'] ?? ''),
                    'cleanup_hash' => (string) ($cleanup['cleanup_hash'] ?? ''),
                ];
            }
        }
        unset($record);

        usort($records, static fn (array $a, array $b): int => ((string) ($b['recorded_at'] ?? '')) <=> ((string) ($a['recorded_at'] ?? '')));

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_records.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-756',
            'area_id' => $areaId,
            'sandbox_count' => count($records),
            'active_sandbox_count' => count(array_filter($records, static fn (array $r): bool => (string) ($r['lifecycle_state'] ?? '') !== self::STATUS_CLEANED)),
            'cleaned_sandbox_count' => count(array_filter($records, static fn (array $r): bool => (string) ($r['lifecycle_state'] ?? '') === self::STATUS_CLEANED)),
            'sandboxes' => array_values($records),
            'claim_policy' => $this->claimPolicy(false, false),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $sandboxId, ?string $areaId = null): ?array
    {
        foreach ($this->listSandboxes($areaId)['sandboxes'] ?? [] as $record) {
            if (is_array($record) && (string) ($record['sandbox_id'] ?? '') === $sandboxId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Safely removes a previously materialized AP-756 sandbox.
     *
     * Conservative by design: it only ever touches the isolated worktree that
     * lives inside the controlled worktrees root, never runs git reset/checkout,
     * never deletes a dirty worktree or an unmerged branch without an explicit
     * operator flag, and never touches product code, providers, merge or deploy.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cleanupSandbox(array $input): array
    {
        $sandboxId = trim((string) ($input['sandbox_id'] ?? ''));
        if ($sandboxId === '') {
            return $this->blockedCleanup('', '', 'sandbox_id_required', 'AP-756 cleanup requires an explicit sandbox_id.');
        }

        $areaHint = trim((string) ($input['area_id'] ?? '')) ?: null;
        $located = $this->locateMaterializeRecord($sandboxId, $areaHint);
        if ($located === null) {
            return $this->blockedCleanup($sandboxId, (string) ($areaHint ?? ''), 'sandbox_record_not_found', 'No AP-756 materialized sandbox record was found for this id.');
        }

        [$areaId, $record] = $located;

        $execute = (bool) ($input['remove_sandbox'] ?? false);
        $allowDirty = (bool) ($input['allow_dirty_removal'] ?? false);
        $deleteBranch = (bool) ($input['delete_branch'] ?? false);
        $allowUnmerged = (bool) ($input['allow_unmerged_branch_delete'] ?? false);

        $repoRoot = trim((string) ($input['repo_root'] ?? '')) ?: (string) data_get($record, 'materialization.repo_root', '');
        $repoRoot = $repoRoot !== '' ? (realpath($repoRoot) ?: $repoRoot) : '';
        $branchName = (string) data_get($record, 'materialization.branch_name', '');
        $worktreePath = (string) data_get($record, 'materialization.worktree_path', '');
        $baseCommit = (string) data_get($record, 'materialization.base_commit', '');

        $prior = $this->latestCleanupEvent($areaId, $sandboxId);
        if ($prior !== null && (bool) ($prior['cleaned'] ?? false)) {
            return $prior + ['cleanup_storage_status' => 'existing'];
        }

        $controlledRoot = $this->storageDir().DIRECTORY_SEPARATOR.'worktrees';
        if ($worktreePath === '' || ! $this->pathWithin($worktreePath, $controlledRoot)) {
            return $this->blockedCleanup($sandboxId, $areaId, 'worktree_path_outside_controlled_root', 'Recorded worktree path is not inside the AP-756 controlled worktrees root; refusing to remove anything.', [
                'target' => $this->cleanupTarget($repoRoot, $branchName, $worktreePath, $baseCommit),
            ]);
        }

        $safety = $this->cleanupSafety($repoRoot, $worktreePath, $branchName, $baseCommit);

        $blockers = [];
        if ($safety['worktree_dirty'] && ! $allowDirty) {
            $blockers[] = 'worktree_dirty_requires_allow_dirty_removal';
        }
        if ($deleteBranch && $safety['branch_has_unmerged_commits'] && ! $allowUnmerged) {
            $blockers[] = 'branch_has_unmerged_commits_requires_allow_unmerged_branch_delete';
        }

        $payload = [
            'schema_version' => self::CLEANUP_SCHEMA,
            'ap_contract' => 'AP-756',
            'status' => self::STATUS_PLANNED,
            'mode' => $execute ? 'cleanup_worktree' : 'dry_run_cleanup_plan',
            'cleaned' => false,
            'area_id' => $areaId,
            'sandbox_id' => $sandboxId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-726', 'AP-756'],
            'source_refs' => [
                'sandbox_hash' => (string) ($record['sandbox_hash'] ?? ''),
                'handoff_hash' => (string) data_get($record, 'source_refs.handoff_hash', ''),
                'handoff_id' => (string) data_get($record, 'source_refs.handoff_id', ''),
            ],
            'target' => $this->cleanupTarget($repoRoot, $branchName, $worktreePath, $baseCommit),
            'safety' => $safety,
            'requested' => [
                'execute' => $execute,
                'allow_dirty_removal' => $allowDirty,
                'delete_branch' => $deleteBranch,
                'allow_unmerged_branch_delete' => $allowUnmerged,
            ],
            'actions' => [
                'worktree_removed' => false,
                'branch_deleted' => false,
            ],
            'blockers' => [],
            'next_actions' => $this->cleanupNextActions($execute, false),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->cleanupClaimPolicy($execute, false, false),
        ];

        if (! $execute) {
            $payload['blockers'] = $blockers;
            $payload['cleanup_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
            $payload['generated_at'] = $this->now();

            return $payload + ['cleanup_storage_status' => 'projected'];
        }

        if ($blockers !== []) {
            return $this->blockedCleanup($sandboxId, $areaId, $blockers[0], 'AP-756 cleanup safety guard blocked removal.', [
                'target' => $payload['target'],
                'safety' => $safety,
                'blockers' => $blockers,
            ]);
        }

        $forceInternalArtifactsOnly = ! $safety['worktree_dirty'] && (int) ($safety['ignored_internal_artifact_count'] ?? 0) > 0;
        $removal = $this->runWorktreeRemove($repoRoot, $worktreePath, $allowDirty || $forceInternalArtifactsOnly);
        if (($removal['status'] ?? '') === self::STATUS_BLOCKED) {
            return $this->blockedCleanup($sandboxId, $areaId, (string) ($removal['reason'] ?? 'git_worktree_remove_failed'), (string) ($removal['detail'] ?? 'git worktree remove failed.'), [
                'target' => $payload['target'],
                'safety' => $safety,
                'git_result' => $removal,
            ]);
        }

        $branchDeleted = false;
        if ($deleteBranch) {
            $branch = $this->runBranchDelete($repoRoot, $branchName, $allowUnmerged);
            $branchDeleted = (bool) ($branch['branch_deleted'] ?? false);
            $payload['branch_delete_result'] = $branch;
        }

        $payload['status'] = self::STATUS_CLEANED;
        $payload['cleaned'] = true;
        $payload['actions'] = [
            'worktree_removed' => (bool) ($removal['worktree_removed'] ?? false),
            'worktree_already_absent' => (string) ($removal['note'] ?? '') === 'worktree_already_absent',
            'branch_deleted' => $branchDeleted,
        ];
        $payload['next_actions'] = $this->cleanupNextActions(true, true);
        $payload['claim_policy'] = $this->cleanupClaimPolicy(true, (bool) ($removal['worktree_removed'] ?? false), $branchDeleted);
        $payload['cleanup_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->recordCleanup($areaId, $payload);
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return list<array<string,mixed>>
     */
    private function readyHandoffs(array $preflight): array
    {
        $out = [];
        if (is_array($preflight['handoff_packet'] ?? null)) {
            $packet = $preflight['handoff_packet'];
            $route = (string) ($packet['route'] ?? '');
            if (in_array($route, [AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, AreaFocusDevForgeRouterService::ROUTE_FORGE], true)) {
                $hash = (string) ($packet['handoff_hash'] ?? $preflight['preflight_hash'] ?? $preflight['report_hash'] ?? '');
                $out[] = [
                    'handoff_hash' => $hash !== '' ? $hash : 'sha256:'.MissionCanonicalHash::sha256([$packet, $preflight['branch_plan'] ?? []]),
                    'handoff_id' => (string) ($packet['handoff_id'] ?? $packet['work_order_id'] ?? ''),
                    'route' => $route,
                    'target_owner' => (string) ($packet['target_owner'] ?? $route),
                    'work_order_id' => (string) ($packet['work_order_id'] ?? ''),
                    'work_order_hash' => (string) ($packet['work_order_hash'] ?? ''),
                    'decision_id' => (string) ($packet['decision_id'] ?? ''),
                    'decision_hash' => (string) ($packet['decision_hash'] ?? ''),
                    'branch_plan' => is_array($preflight['branch_plan'] ?? null) ? $preflight['branch_plan'] : [],
                ];
            }
        }

        foreach ((array) ($preflight['handoffs'] ?? []) as $handoff) {
            if (! is_array($handoff)) {
                continue;
            }
            if ((string) ($handoff['handoff_status'] ?? '') !== AreaFocusBranchSandboxHandoffService::HO_READY) {
                continue;
            }
            $route = (string) ($handoff['route'] ?? '');
            if (! in_array($route, [AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, AreaFocusDevForgeRouterService::ROUTE_FORGE], true)) {
                continue;
            }
            $out[] = [
                'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
                'handoff_id' => (string) ($handoff['handoff_id'] ?? $handoff['work_order_id'] ?? ''),
                'route' => $route,
                'target_owner' => (string) ($handoff['target_owner'] ?? $route),
                'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
                'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
                'decision_id' => (string) ($handoff['decision_id'] ?? data_get($handoff, 'operator_receipt.decision_id', '')),
                'decision_hash' => (string) ($handoff['decision_hash'] ?? data_get($handoff, 'operator_receipt.decision_hash', '')),
                'branch_plan' => is_array($handoff['branch_plan'] ?? null) ? $handoff['branch_plan'] : [],
            ];
        }

        return array_values(array_filter($out, static fn (array $h): bool => (string) ($h['handoff_hash'] ?? '') !== ''));
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>|null
     */
    private function matchingReadyHandoff(array $preflight, string $targetHash): ?array
    {
        foreach ($this->readyHandoffs($preflight) as $handoff) {
            if ((string) ($handoff['handoff_hash'] ?? '') === $targetHash) {
                return $handoff;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{reason:string,detail:string}|null
     */
    private function sandboxReceiptBlocker(array $receipt): ?array
    {
        if ($receipt === []) {
            return ['reason' => 'sandbox_receipt_required', 'detail' => 'An explicit AP-756 operator sandbox receipt is required.'];
        }
        if (! in_array((string) ($receipt['decision'] ?? ''), ['materialize_sandbox', 'approve_branch_sandbox'], true)) {
            return ['reason' => 'sandbox_decision_required', 'detail' => 'Sandbox receipt decision must be materialize_sandbox or approve_branch_sandbox.'];
        }
        if ($this->targetHash($receipt) === '') {
            return ['reason' => 'target_handoff_hash_required', 'detail' => 'Sandbox receipt must name target_handoff_hash or target_hash.'];
        }
        if (trim((string) ($receipt['operator_actor'] ?? '')) === '') {
            return ['reason' => 'operator_actor_required', 'detail' => 'Sandbox receipt must name the operator actor.'];
        }

        return null;
    }

    /**
     * @return array{reason:string,detail:string}|null
     */
    private function branchNameBlocker(string $branchName): ?array
    {
        if ($branchName === '') {
            return ['reason' => 'branch_name_required', 'detail' => 'AP-726 branch plan did not provide a branch name.'];
        }
        if (! str_starts_with($branchName, 'area-focus/') && ! str_starts_with($branchName, 'atlas/area-focus/')) {
            return ['reason' => 'branch_prefix_not_allowed', 'detail' => 'AP-756 only materializes area-focus or atlas/area-focus branches.'];
        }
        if (str_contains($branchName, '..') || str_starts_with($branchName, '/') || str_ends_with($branchName, '/') || str_contains($branchName, '//')) {
            return ['reason' => 'branch_name_unsafe', 'detail' => 'Branch name contains an unsafe path sequence.'];
        }
        if (! preg_match('/\A[a-zA-Z0-9._\/-]+\z/', $branchName)) {
            return ['reason' => 'branch_name_unsafe', 'detail' => 'Branch name contains characters outside the AP-756 allowlist.'];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function gitPreflight(string $repoRoot, string $branchName, string $baseRef, string $worktreePath): array
    {
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'repo_root_missing', 'detail' => 'Repository root does not exist.'];
        }

        $inside = $this->runGit($repoRoot, ['git', 'rev-parse', '--is-inside-work-tree']);
        if (! $inside['ok'] || trim((string) ($inside['stdout'] ?? '')) !== 'true') {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'repo_root_not_git', 'detail' => 'Repository root is not a git worktree.'];
        }

        if (is_dir($worktreePath) || is_file($worktreePath)) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'worktree_path_exists_without_record', 'detail' => 'Worktree path already exists without an AP-756 record.'];
        }

        $branchExists = $this->runGit($repoRoot, ['git', 'show-ref', '--verify', '--quiet', 'refs/heads/'.$branchName]);
        if ($branchExists['ok']) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'branch_already_exists_without_record', 'detail' => 'Branch already exists without an AP-756 record.'];
        }

        $base = $this->runGit($repoRoot, ['git', 'rev-parse', '--verify', $baseRef.'^{commit}']);
        if (! $base['ok']) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'base_ref_not_found', 'detail' => 'Base ref could not be resolved to a commit.'];
        }

        $branch = $this->runGit($repoRoot, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $status = $this->runGit($repoRoot, ['git', 'status', '--porcelain=v1', '--untracked-files=all']);

        return [
            'status' => 'ready',
            'base_commit' => trim((string) ($base['stdout'] ?? '')),
            'source_branch' => trim((string) ($branch['stdout'] ?? '')),
            'source_worktree_dirty' => trim((string) ($status['stdout'] ?? '')) !== '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorktreeAdd(string $repoRoot, string $branchName, string $worktreePath, string $baseRef): array
    {
        File::ensureDirectoryExists(dirname($worktreePath));

        $result = $this->runGit($repoRoot, ['git', 'worktree', 'add', '-b', $branchName, $worktreePath, $baseRef], 120);
        if (! $result['ok']) {
            return [
                'status' => self::STATUS_BLOCKED,
                'reason' => 'git_worktree_add_failed',
                'detail' => 'git worktree add failed.',
                'stderr_hash' => hash('sha256', (string) ($result['stderr'] ?? '')),
            ];
        }

        $branch = $this->runGit($worktreePath, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        if (! $branch['ok']) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'created_worktree_not_git', 'detail' => 'Created worktree is not readable by git.'];
        }

        // A git worktree does NOT inherit gitignored runtime dependencies (vendor/, .env),
        // but the owner runtime runs `<worktree>/artisan` which require()s
        // `<worktree>/vendor/autoload.php`. Without this every owner command — senior-loop
        // AND minimax-worker — dies at startup with exit 255 (autoload not found), which the
        // loop sees as an execution failure (and a streak even trips the cascade halt). Link
        // the canonical checkout's vendor + .env into the sandbox so artisan boots; the
        // worktree's own (possibly edited) source is still what executes.
        $this->linkRuntimeDependencies($repoRoot, $worktreePath);

        return [
            'status' => self::STATUS_MATERIALIZED,
            'current_worktree_branch' => trim((string) ($branch['stdout'] ?? '')),
        ];
    }

    /**
     * Symlink the canonical checkout's gitignored runtime dependencies into a freshly
     * created sandbox worktree so `<worktree>/artisan` can bootstrap. Best-effort and
     * idempotent: a missing source or an existing target is skipped, and any failure is
     * non-fatal (the owner command would then block honestly on autoload, never fabricate).
     */
    private function linkRuntimeDependencies(string $repoRoot, string $worktreePath): void
    {
        $repoRoot = rtrim($repoRoot, DIRECTORY_SEPARATOR);
        $worktreePath = rtrim($worktreePath, DIRECTORY_SEPARATOR);

        foreach (['vendor', '.env'] as $dependency) {
            $source = $repoRoot.DIRECTORY_SEPARATOR.$dependency;
            $target = $worktreePath.DIRECTORY_SEPARATOR.$dependency;

            if (! file_exists($source) || file_exists($target) || is_link($target)) {
                continue;
            }

            try {
                @symlink($source, $target);
            } catch (Throwable) {
                // Non-fatal: the owner command will block honestly if it cannot boot.
            }
        }
    }

    /**
     * @param  list<string>  $command
     * @return array<string,mixed>
     */
    private function runGit(string $cwd, array $command, int $timeout = 30): array
    {
        try {
            $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'), null, $timeout);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'stdout' => $process->getOutput(),
                'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => 255,
                'stdout' => '',
                'stderr' => AtlasSecurity::redactString($e->getMessage()),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['sandbox_storage_status' => 'projected'];
        }

        $path = $this->sandboxRecordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['sandbox_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['sandbox_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;

        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['sandbox_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $sandboxId): ?array
    {
        if ($sandboxId === '' || ! is_file($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $decoded = json_decode($lines[$index], true);
            if (is_array($decoded)
                && (string) ($decoded['sandbox_id'] ?? '') === $sandboxId
                && (string) ($decoded['schema_version'] ?? '') === self::RECORD_SCHEMA) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    private function resumeExistingSandbox(array $existing, string $areaId, array $preflight): array
    {
        $status = (string) ($existing['status'] ?? '');
        $worktreePath = (string) data_get($existing, 'materialization.worktree_path', '');
        if ($status === self::STATUS_MATERIALIZED && $worktreePath !== '' && ! is_dir($worktreePath)) {
            return $this->blocked($areaId, 'sandbox_record_worktree_missing', 'AP-756 record exists but the isolated worktree is missing; run cleanup or materialize a new sandbox before autonomous consumption.', $preflight, [
                'sandbox_id' => (string) ($existing['sandbox_id'] ?? ''),
                'recorded_worktree_path_hash' => hash('sha256', $worktreePath),
            ]);
        }

        $worktreePresent = $worktreePath !== '' && is_dir($worktreePath);

        return $existing + [
            'sandbox_storage_status' => 'existing',
            'autonomous_cycle_ready' => $status === self::STATUS_MATERIALIZED && $worktreePresent,
        ];
    }

    /**
     * @return list<string>
     */
    private function areasWithRecords(): array
    {
        if (! is_dir($this->storageDir())) {
            return [];
        }

        $areas = [];
        foreach (glob($this->storageDir().DIRECTORY_SEPARATOR.'*.jsonl') ?: [] as $path) {
            $areas[] = basename($path, '.jsonl');
        }

        return array_values(array_unique($areas));
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $preflight = [], array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-756',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'branch_sandbox_materialization',
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-726', 'AP-756'],
            'source_refs' => [
                'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
                'handoff_report_hash' => (string) ($preflight['report_hash'] ?? ''),
                'preflight_status' => (string) ($preflight['status'] ?? ''),
            ],
            'blockers' => [$reason],
            'next_actions' => ['Resolve the AP-756 blocker before materializing any branch sandbox.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false, false),
        ] + $extra;
        $payload['sandbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $branchPlan
     */
    private function branchName(array $branchPlan): string
    {
        return trim((string) ($branchPlan['proposed_branch_name'] ?? $branchPlan['branch_name'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $repoRoot = trim((string) ($input['repo_root'] ?? ''));
        if ($repoRoot !== '') {
            return realpath($repoRoot) ?: $repoRoot;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '');
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     */
    private function sandboxId(string $areaId, array $handoff, array $receipt, string $branchName): string
    {
        $explicit = trim((string) ($receipt['sandbox_id'] ?? ''));
        if ($explicit !== '') {
            return $this->slug($explicit, '_');
        }

        return 'afsb_'.substr(MissionCanonicalHash::sha256([
            'ap' => 'AP-756',
            'area_id' => $areaId,
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'branch_name' => $branchName,
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
        ]), 0, 18);
    }

    private function worktreePath(string $sandboxId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.'worktrees'.DIRECTORY_SEPARATOR.$sandboxId;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function targetHash(array $receipt): string
    {
        return trim((string) ($receipt['target_handoff_hash'] ?? $receipt['target_hash'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     * @return array<string,string>
     */
    private function sourceRefs(array $preflight, array $handoff, array $receipt): array
    {
        return [
            'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
            'handoff_report_hash' => (string) ($preflight['report_hash'] ?? ''),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'handoff_id' => (string) ($handoff['handoff_id'] ?? ''),
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
            'decision_id' => (string) ($handoff['decision_id'] ?? ''),
            'sandbox_receipt_id' => (string) ($receipt['sandbox_receipt_id'] ?? $receipt['receipt_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,string>
     */
    private function receiptSummary(array $receipt): array
    {
        return [
            'decision' => (string) ($receipt['decision'] ?? ''),
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
            'target_handoff_hash' => $this->targetHash($receipt),
            'sandbox_receipt_id' => (string) ($receipt['sandbox_receipt_id'] ?? $receipt['receipt_id'] ?? ''),
            'receipt_hash' => (string) ($receipt['sandbox_receipt_hash'] ?? $receipt['receipt_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,string|bool>
     */
    private function registrySummary(array $registry): array
    {
        return [
            'ap_contract' => (string) ($registry['ap_contract'] ?? 'AP-770'),
            'status' => (string) ($registry['status'] ?? ''),
            'registry_id' => (string) ($registry['registry_id'] ?? ''),
            'branch_key' => (string) ($registry['branch_key'] ?? ''),
            'registry_hash' => (string) ($registry['registry_hash'] ?? ''),
            'storage_status' => (string) ($registry['registry_storage_status'] ?? ''),
            'active_collision_found' => (bool) data_get($registry, 'collision_guard.active_collision_found', false),
        ];
    }

    /**
     * @return list<string>
     */
    private function nextActions(bool $materialized): array
    {
        if (! $materialized) {
            return [
                'Review the AP-756 plan and confirm repository/base ref before passing --materialize-sandbox.',
                'No branch or worktree exists yet; this is projection-only.',
            ];
        }

        return [
            'Review the isolated worktree before any Dev/Forge owner consumption.',
            'Runtime execution still requires AP-749 owner-specific consumption and owner runtime gates.',
            'Merge, deploy, external push, secrets and destructive changes remain blocked without explicit operator approval.',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'branch_sandbox_preflight' => ['ap' => 'AP-726', 'owner_service' => AreaFocusBranchSandboxPreflightService::class],
            'branch_sandbox_handoff' => ['ap' => 'AP-726', 'owner_service' => AreaFocusBranchSandboxHandoffService::class],
            'dev_forge_release' => ['ap' => 'AP-747', 'owner_service' => AreaFocusDevForgeReleaseService::class],
            'owner_consumption_gate' => ['ap' => 'AP-749', 'owner_service' => AreaFocusOwnerQueueConsumptionGateService::class],
            'product_mode_controls' => ['ap' => 'AP-754/AP-755', 'role' => 'operator controls and kill switch visibility'],
            'branch_lifecycle_registry' => ['ap' => 'AP-770', 'owner_service' => StewardshipBranchLifecycleRegistryService::class],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $materializeRequested, bool $materialized): array
    {
        return [
            'mode' => $materializeRequested ? 'operator_receipted_branch_worktree_materialization' : 'dry_run_materialization_plan',
            'requires_operator_sandbox_receipt' => true,
            'branch_created' => $materialized,
            'worktree_created' => $materialized,
            'target_repo_mutated' => false,
            'fix_applied' => false,
            'runtime_execution_started' => false,
            'provider_invoked' => false,
            'dev_or_forge_dispatched' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'pushed_external' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'auto_approved' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}|null
     */
    private function locateMaterializeRecord(string $sandboxId, ?string $areaHint): ?array
    {
        $areas = $areaHint !== null && trim($areaHint) !== ''
            ? [$areaHint]
            : $this->areasWithRecords();

        foreach ($areas as $area) {
            $record = $this->findRecord($this->sandboxRecordPath($area), $sandboxId);
            if ($record !== null) {
                return [$area, $record];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestCleanupEvent(string $areaId, string $sandboxId): ?array
    {
        $path = $this->sandboxRecordPath($areaId);
        if ($sandboxId === '' || ! is_file($path)) {
            return null;
        }

        $latest = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)
                && (string) ($decoded['schema_version'] ?? '') === self::CLEANUP_SCHEMA
                && (string) ($decoded['sandbox_id'] ?? '') === $sandboxId) {
                $latest = $decoded;
            }
        }

        return $latest;
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanupTarget(string $repoRoot, string $branchName, string $worktreePath, string $baseCommit): array
    {
        return [
            'repo_root' => $repoRoot,
            'repo_root_hash' => hash('sha256', $repoRoot),
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
            'worktree_path_hash' => hash('sha256', $worktreePath),
            'base_commit' => $baseCommit,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanupSafety(string $repoRoot, string $worktreePath, string $branchName, string $baseCommit): array
    {
        $worktreeExists = is_dir($worktreePath);
        $worktreeDirty = false;
        $ignoredInternalArtifactCount = 0;
        if ($worktreeExists) {
            $status = $this->runGit($worktreePath, ['git', 'status', '--porcelain=v1', '--untracked-files=all']);
            if ($status['ok']) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", (string) ($status['stdout'] ?? '')))));
                $dirtyLines = array_values(array_filter($lines, function (string $line) use (&$ignoredInternalArtifactCount): bool {
                    if (str_starts_with($line, '?? .atlas/')) {
                        $ignoredInternalArtifactCount++;

                        return false;
                    }

                    return true;
                }));
                $worktreeDirty = $dirtyLines !== [];
            }
        }

        $commitsAhead = 0;
        $branchMergedIntoHead = false;
        if ($repoRoot !== '' && $branchName !== '' && $baseCommit !== '') {
            $rev = $this->runGit($repoRoot, ['git', 'rev-list', '--count', $baseCommit.'..'.$branchName]);
            if ($rev['ok']) {
                $commitsAhead = (int) trim((string) ($rev['stdout'] ?? '0'));
            }
            $merged = $this->runGit($repoRoot, ['git', 'merge-base', '--is-ancestor', $branchName, 'HEAD']);
            $branchMergedIntoHead = $merged['ok'];
        }

        return [
            'worktree_exists' => $worktreeExists,
            'worktree_dirty' => $worktreeDirty,
            'ignored_internal_artifact_count' => $ignoredInternalArtifactCount,
            'branch_commits_ahead' => $commitsAhead,
            'branch_merged_into_head' => $branchMergedIntoHead,
            'branch_has_unmerged_commits' => $commitsAhead > 0 && ! $branchMergedIntoHead,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorktreeRemove(string $repoRoot, string $worktreePath, bool $force): array
    {
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'repo_root_missing', 'detail' => 'Repository root does not exist for cleanup.'];
        }

        if (! is_dir($worktreePath)) {
            // Worktree already gone — prune only the stale admin entry, never touch product code.
            $this->runGit($repoRoot, ['git', 'worktree', 'prune']);

            return ['status' => self::STATUS_CLEANED, 'worktree_removed' => false, 'note' => 'worktree_already_absent'];
        }

        $command = ['git', 'worktree', 'remove'];
        if ($force) {
            $command[] = '--force';
        }
        $command[] = $worktreePath;

        $result = $this->runGit($repoRoot, $command, 120);
        if (! $result['ok']) {
            return [
                'status' => self::STATUS_BLOCKED,
                'reason' => 'git_worktree_remove_failed',
                'detail' => 'git worktree remove failed (worktree may have uncommitted changes; pass allow_dirty_removal).',
                'stderr_hash' => hash('sha256', (string) ($result['stderr'] ?? '')),
            ];
        }

        return ['status' => self::STATUS_CLEANED, 'worktree_removed' => true];
    }

    /**
     * @return array<string,mixed>
     */
    private function runBranchDelete(string $repoRoot, string $branchName, bool $allowUnmerged): array
    {
        if ($branchName === '') {
            return ['branch_deleted' => false, 'detail' => 'No branch name recorded for this sandbox.'];
        }

        $result = $this->runGit($repoRoot, ['git', 'branch', $allowUnmerged ? '-D' : '-d', $branchName]);
        if (! $result['ok']) {
            return [
                'branch_deleted' => false,
                'detail' => 'git branch delete refused (likely unmerged commits; pass allow_unmerged_branch_delete).',
                'stderr_hash' => hash('sha256', (string) ($result['stderr'] ?? '')),
            ];
        }

        return ['branch_deleted' => true];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function recordCleanup(string $areaId, array $payload): array
    {
        $path = $this->sandboxRecordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $event = ['recorded_at' => $this->now()] + $payload;
        File::append($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $event + ['cleanup_storage_status' => 'recorded'];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blockedCleanup(string $sandboxId, string $areaId, string $reason, string $detail, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::CLEANUP_SCHEMA,
            'ap_contract' => 'AP-756',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'sandbox_cleanup',
            'cleaned' => false,
            'area_id' => $areaId,
            'sandbox_id' => $sandboxId,
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-726', 'AP-756'],
            'actions' => ['worktree_removed' => false, 'branch_deleted' => false],
            'blockers' => [$reason],
            'next_actions' => ['Resolve the AP-756 cleanup blocker before removing any sandbox.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->cleanupClaimPolicy(false, false, false),
        ] + $extra;
        $payload['cleanup_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function cleanupNextActions(bool $execute, bool $cleaned): array
    {
        if (! $execute) {
            return [
                'Review the cleanup plan and confirm the worktree/branch before passing --remove-sandbox.',
                'Nothing was removed; this is a dry-run cleanup plan.',
            ];
        }

        if ($cleaned) {
            return [
                'Sandbox worktree removed; the AP-756 record is retained with an append-only cleanup event.',
                'Re-running owner consumption against this sandbox now requires a fresh AP-756 materialization.',
            ];
        }

        return ['Cleanup did not complete; inspect blockers before retrying.'];
    }

    private function pathWithin(string $path, string $root): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root);
    }

    /**
     * @return array<string,bool|string>
     */
    private function cleanupClaimPolicy(bool $executeRequested, bool $worktreeRemoved, bool $branchDeleted): array
    {
        return [
            'mode' => $executeRequested ? 'sandbox_cleanup_execution' : 'dry_run_cleanup_plan',
            'operates_only_inside_controlled_worktree_root' => true,
            'sandbox_worktree_removed' => $worktreeRemoved,
            'sandbox_branch_deleted' => $branchDeleted,
            'target_repo_mutated' => false,
            'product_code_mutated' => false,
            'destructive_git_reset' => false,
            'destructive_git_checkout' => false,
            'user_changes_discarded' => false,
            'fix_applied' => false,
            'runtime_execution_started' => false,
            'provider_invoked' => false,
            'dev_or_forge_dispatched' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'pushed_external' => false,
            'secret_access' => false,
            'auto_approved' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset(
            $copy['generated_at'],
            $copy['recorded_at'],
            $copy['sandbox_hash'],
            $copy['sandbox_storage_status'],
            $copy['cleanup_hash'],
            $copy['cleanup_storage_status'],
        );

        return $copy;
    }

    private function slug(string $value, string $separator = '-'): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', $separator, trim($value)) ?? '';

        return trim(strtolower($slug), $separator) ?: 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
