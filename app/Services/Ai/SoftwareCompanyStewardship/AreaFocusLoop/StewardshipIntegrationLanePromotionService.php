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
 * AP-783 · Stewardship Integration Lane Promotion.
 *
 * Fast-forwards a visible atlas/integration/* lane into a clean base ref using
 * AP-775 lease + AP-769 ff-only governance. Never mutates a dirty base worktree.
 */
final class StewardshipIntegrationLanePromotionService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.integration_lane_promotion.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.integration_lane_promotion_record.v1';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_PROMOTED_NOOP = 'promoted_noop';

    public const STATUS_ALREADY_PROMOTED = 'already_promoted';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipRepoMergeLeaseService $repoMergeLease,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->mergeGovernor->setStorageRootForTesting($dir !== null ? $dir.'/merge_governor' : null);
        $this->repoMergeLease->setStorageRootForTesting($dir !== null ? $dir.'/repo_merge_lease' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/integration_lane_promotions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/integration_lane_promotions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function promote(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $laneRef = trim((string) ($input['lane_ref'] ?? ''));
        $record = (bool) ($input['record'] ?? false);
        $leaseOwner = trim((string) ($input['lease_owner'] ?? ''));
        if ($leaseOwner === '') {
            $leaseOwner = trim((string) ($input['owner'] ?? $input['runner_id'] ?? 'integration_lane_promotion_'.$areaId));
        }

        if ($laneRef === '') {
            return $this->blocked($areaId, $laneRef, $baseRef, 'lane_ref_required', 'AP-783 requires an integration lane ref.', [
                'repo_root' => $repoRoot,
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_untouched_on_block' => true,
            ]);
        }
        if ($this->unsafeLaneRef($laneRef)) {
            return $this->blocked($areaId, $laneRef, $baseRef, 'invalid_lane_ref', 'lane_ref must be under atlas/integration/.', [
                'repo_root' => $repoRoot,
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_untouched_on_block' => true,
            ]);
        }
        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return $this->blocked($areaId, $laneRef, $baseRef, 'repo_root_not_git_repository', 'AP-783 requires a git repository root.', [
                'repo_root' => $repoRoot,
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_untouched_on_block' => true,
            ]);
        }
        if ($this->revParse($repoRoot, $baseRef) === '') {
            return $this->blocked($areaId, $laneRef, $baseRef, 'base_ref_not_found', 'Base ref was not found.', [
                'repo_root' => $repoRoot,
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_untouched_on_block' => true,
            ]);
        }
        if ($this->revParse($repoRoot, $laneRef) === '') {
            return $this->blocked($areaId, $laneRef, $baseRef, 'lane_ref_not_found', 'Integration lane ref was not found.', [
                'repo_root' => $repoRoot,
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_untouched_on_block' => true,
            ]);
        }

        $baseBefore = $this->revParse($repoRoot, $baseRef);
        $laneCommit = $this->revParse($repoRoot, $laneRef);

        if (! $this->workingTreeClean($repoRoot)) {
            return $this->blocked($areaId, $laneRef, $baseRef, 'base_worktree_dirty', 'Base worktree must be clean before promoting an integration lane to the base ref.', [
                'blockers' => ['base_worktree_dirty'],
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_before' => $baseBefore,
                'base_after' => $baseBefore,
                'lane_commit' => $laneCommit,
                'repo_root' => $repoRoot,
                'promoted' => false,
                'base_untouched_on_block' => true,
            ]);
        }

        if ($baseBefore === $laneCommit) {
            return $this->successReceipt(
                $areaId,
                $repoRoot,
                $laneRef,
                $baseRef,
                self::STATUS_ALREADY_PROMOTED,
                $baseBefore,
                $baseBefore,
                $laneCommit,
                false,
                'not_required',
                StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE,
                [],
                null,
                null,
                $record,
                ['base_already_at_lane_commit' => true],
            );
        }

        if (! $this->isAncestor($repoRoot, $baseRef, $laneRef)) {
            return $this->blocked($areaId, $laneRef, $baseRef, 'lane_not_ahead_of_base', 'Integration lane must be a fast-forward descendant of the base ref.', [
                'blockers' => ['lane_not_ahead_of_base'],
                'lease_status' => 'not_attempted',
                'governance_status' => 'not_run',
                'base_before' => $baseBefore,
                'base_after' => $baseBefore,
                'lane_commit' => $laneCommit,
                'repo_root' => $repoRoot,
                'promoted' => false,
                'base_untouched_on_block' => true,
            ]);
        }

        $leasePayload = null;
        $leaseAcquired = false;
        $governance = null;

        try {
            $leasePayload = $this->repoMergeLease->acquire([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'owner' => $leaseOwner,
                'ttl_seconds' => (int) ($input['lease_ttl_seconds'] ?? 1800),
            ]);
            if (($leasePayload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_BLOCKED) {
                return $this->blocked($areaId, $laneRef, $baseRef, (string) ($leasePayload['reason'] ?? 'repo_merge_lease_blocked'), (string) ($leasePayload['detail'] ?? 'AP-775 blocked integration lane promotion.'), [
                    'blockers' => array_values((array) ($leasePayload['blockers'] ?? [(string) ($leasePayload['reason'] ?? 'repo_merge_lease_blocked')])),
                    'lease_status' => 'blocked',
                    'repo_merge_lease' => $leasePayload,
                    'governance_status' => 'not_run',
                    'base_before' => $baseBefore,
                    'base_after' => $baseBefore,
                    'lane_commit' => $laneCommit,
                    'repo_root' => $repoRoot,
                    'promoted' => false,
                    'base_untouched_on_block' => true,
                ]);
            }

            $leaseAcquired = ($leasePayload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_ACQUIRED;
            $authorizedScope = $this->authorizedLanePromotionScope($areaId, $repoRoot, $baseBefore, $laneCommit);

            $governance = $this->mergeGovernor->evaluate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'branch_ref' => $laneRef,
                'auto_merge' => true,
                'execute_merge' => true,
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 12),
                'run_validation' => (bool) ($input['run_validation'] ?? false),
                'test_commands' => array_values(array_filter((array) ($input['test_commands'] ?? []), 'is_string')),
                'worktree_path' => trim((string) ($input['worktree_path'] ?? '')),
                'injected_plan_slice_auto_merge' => (bool) ($authorizedScope['injected_plan_slice_auto_merge'] ?? false),
                'injected_plan_slice_allowed_files' => (array) ($authorizedScope['allowed_files'] ?? []),
                'record_governance' => $record,
            ]);

            $governanceStatus = (string) ($governance['status'] ?? 'unknown');
            $blockers = array_values(array_unique(array_map('strval', (array) ($governance['blockers'] ?? []))));

            if ($governanceStatus !== StewardshipBranchMergeGovernorService::STATUS_MERGED) {
                $reason = $governanceStatus === StewardshipBranchMergeGovernorService::STATUS_BLOCKED
                    ? 'governance_blocked'
                    : 'lane_not_auto_merge_eligible';

                $releasePayload = $this->repoMergeLease->release([
                    'area_id' => $areaId,
                    'repo_root' => $repoRoot,
                    'base_ref' => $baseRef,
                    'owner' => $leaseOwner,
                    'release_reason' => 'integration_lane_promotion_blocked',
                ]);
                $leaseAcquired = false;

                return $this->blocked($areaId, $laneRef, $baseRef, $reason, 'AP-769 blocked integration lane promotion before or during ff-only merge.', [
                    'blockers' => $blockers !== [] ? $blockers : [$reason],
                    'lease_status' => ($releasePayload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_RELEASED ? 'released' : 'release_failed',
                    'repo_merge_lease' => $leasePayload,
                    'repo_merge_lease_release' => $releasePayload,
                    'governance_status' => $governanceStatus,
                    'governance_report' => $governance,
                    'authorized_lane_promotion_scope' => $authorizedScope,
                    'base_before' => $baseBefore,
                    'base_after' => $this->revParse($repoRoot, $baseRef),
                    'lane_commit' => $laneCommit,
                    'repo_root' => $repoRoot,
                    'promoted' => false,
                    'base_untouched_on_block' => $this->revParse($repoRoot, $baseRef) === $baseBefore,
                ]);
            }

            $baseAfter = $this->revParse($repoRoot, $baseRef);
            $promoted = $baseAfter === $laneCommit && $baseAfter !== $baseBefore;
            $releasePayload = $this->repoMergeLease->release([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'owner' => $leaseOwner,
                'release_reason' => 'integration_lane_promotion_finished',
            ]);
            $leaseAcquired = false;

            return $this->successReceipt(
                $areaId,
                $repoRoot,
                $laneRef,
                $baseRef,
                $promoted ? self::STATUS_PROMOTED : self::STATUS_PROMOTED_NOOP,
                $baseBefore,
                $baseAfter,
                $laneCommit,
                $promoted,
                ($releasePayload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_RELEASED ? 'released' : 'release_failed',
                $governanceStatus,
                $blockers,
                $leasePayload,
                $governance,
                $record,
                [
                    'merge_result' => $governance['merge_result'] ?? null,
                    'repo_merge_lease_release' => $releasePayload,
                    'authorized_lane_promotion_scope' => $authorizedScope,
                ],
            );
        } finally {
            if ($leaseAcquired) {
                $this->repoMergeLease->release([
                    'area_id' => $areaId,
                    'repo_root' => $repoRoot,
                    'base_ref' => $baseRef,
                    'owner' => $leaseOwner,
                    'release_reason' => 'integration_lane_promotion_finished',
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $leasePayload
     * @param  array<string,mixed>|null  $governance
     * @param  array<string,mixed>  $evidenceExtra
     * @return array<string,mixed>
     */
    private function successReceipt(
        string $areaId,
        string $repoRoot,
        string $laneRef,
        string $baseRef,
        string $status,
        string $baseBefore,
        string $baseAfter,
        string $laneCommit,
        bool $promoted,
        string $leaseStatus,
        string $governanceStatus,
        array $blockers,
        ?array $leasePayload,
        ?array $governance,
        bool $record,
        array $evidenceExtra = [],
    ): array {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-783',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-775', 'AP-782', 'AP-783'],
            'promotion_id' => 'silp_'.substr(MissionCanonicalHash::sha256([$areaId, $repoRoot, $laneRef, $baseRef, $baseBefore, $laneCommit, $status]), 0, 18),
            'lane_ref' => $laneRef,
            'base_ref' => $baseRef,
            'lease_status' => $leaseStatus,
            'governance_status' => $governanceStatus,
            'base_before' => $baseBefore,
            'base_after' => $baseAfter,
            'lane_commit' => $laneCommit,
            'promoted' => $promoted,
            'base_untouched_on_block' => false,
            'blockers' => $blockers,
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
                'base_commit_before' => $baseBefore,
                'base_commit_after' => $baseAfter,
                'base_untouched_on_block' => false,
            ],
            'integration_lane' => [
                'lane_ref' => $laneRef,
                'lane_commit' => $laneCommit,
                'gitkraken_visible' => true,
            ],
            'lease' => [
                'owner' => '',
                'acquire_status' => $leasePayload['status'] ?? null,
                'release_status' => (string) ($evidenceExtra['repo_merge_lease_release']['status'] ?? $leaseStatus),
                'lease_report' => $leasePayload,
                'lease_release_report' => $evidenceExtra['repo_merge_lease_release'] ?? null,
            ],
            'repo_merge_lease' => $leasePayload,
            'repo_merge_lease_release' => $evidenceExtra['repo_merge_lease_release'] ?? null,
            'governance_report' => $governance,
            'authorized_lane_promotion_scope' => $evidenceExtra['authorized_lane_promotion_scope'] ?? null,
            'evidence' => array_merge([
                'dangerous_actions' => false,
                'ff_only_merge' => true,
                'base_is_at_lane_commit' => $baseAfter === $laneCommit,
            ], $evidenceExtra),
            'claim_policy' => [
                'provider_invoked' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'rebase_performed' => false,
                'squash_performed' => false,
                'force_push_performed' => false,
                'reset_performed' => false,
                'touches_secrets' => false,
                'merge_performed_to_base' => $promoted,
                'dangerous_actions' => false,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['promotion_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, $record);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(
        string $areaId,
        string $laneRef,
        string $baseRef,
        string $reason,
        string $detail,
        array $extra = [],
    ): array {
        $blockers = array_values(array_unique(array_map('strval', (array) ($extra['blockers'] ?? [$reason]))));

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-783',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'promotion_id' => 'silp_'.substr(MissionCanonicalHash::sha256([$areaId, $laneRef, $baseRef, $reason]), 0, 18),
            'lane_ref' => $laneRef,
            'base_ref' => $baseRef,
            'reason' => $reason,
            'detail' => $detail,
            'lease_status' => (string) ($extra['lease_status'] ?? 'not_attempted'),
            'governance_status' => (string) ($extra['governance_status'] ?? 'not_run'),
            'base_before' => (string) ($extra['base_before'] ?? ''),
            'base_after' => (string) ($extra['base_after'] ?? ($extra['base_before'] ?? '')),
            'lane_commit' => (string) ($extra['lane_commit'] ?? ''),
            'promoted' => false,
            'base_untouched_on_block' => (bool) ($extra['base_untouched_on_block'] ?? true),
            'blockers' => $blockers,
            'repo' => [
                'repo_root' => (string) ($extra['repo_root'] ?? ''),
                'repo_root_hash' => (string) ($extra['repo_root'] ?? '') !== '' ? hash('sha256', (string) $extra['repo_root']) : '',
                'base_ref' => $baseRef,
                'base_commit_before' => (string) ($extra['base_before'] ?? ''),
                'base_commit_after' => (string) ($extra['base_after'] ?? ($extra['base_before'] ?? '')),
                'base_untouched_on_block' => (bool) ($extra['base_untouched_on_block'] ?? true),
            ],
            'integration_lane' => [
                'lane_ref' => $laneRef,
                'lane_commit' => (string) ($extra['lane_commit'] ?? ''),
                'gitkraken_visible' => $laneRef !== '',
            ],
            'lease' => [
                'owner' => '',
                'acquire_status' => data_get($extra, 'repo_merge_lease.status'),
                'release_status' => data_get($extra, 'repo_merge_lease_release.status'),
                'lease_report' => $extra['repo_merge_lease'] ?? null,
                'lease_release_report' => $extra['repo_merge_lease_release'] ?? null,
            ],
            'evidence' => [
                'dangerous_actions' => false,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'rebase_performed' => false,
                'squash_performed' => false,
                'force_push_performed' => false,
                'reset_performed' => false,
                'touches_secrets' => false,
                'merge_performed_to_base' => false,
                'dangerous_actions' => false,
            ],
            'generated_at' => $this->now(),
        ] + $extra;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['promotion_storage_status' => 'projected'];
        }

        $path = $this->recordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['promotion_storage_status' => 'recorded'];
    }

    /**
     * AP-783 promotion inherits the narrow AP-774 injected-plan authorization from
     * AP-782 lane receipts. This lets a lane that only contains previously
     * accepted, scoped plan-slice files promote to main without treating all
     * arbitrary integration-lane code as safe.
     *
     * @return array<string,mixed>
     */
    private function authorizedLanePromotionScope(string $areaId, string $repoRoot, string $baseBefore, string $laneCommit): array
    {
        $changedFiles = $this->changedFiles($repoRoot, $baseBefore, $laneCommit);
        $default = [
            'schema_version' => 'atlas.software_company_stewardship.ap783_authorized_lane_scope.v1',
            'status' => 'not_authorized',
            'injected_plan_slice_auto_merge' => false,
            'changed_files' => $changedFiles,
            'allowed_files' => [],
            'covered_files' => [],
            'missing_files' => $changedFiles,
            'receipt_count' => 0,
        ];

        if ($changedFiles === []) {
            return $default + ['status' => 'no_changed_files'];
        }

        $path = $this->integrationLaneRecordPath($areaId);
        if (! is_file($path)) {
            return $default + ['status' => 'integration_lane_receipts_missing'];
        }

        $allowed = [];
        $receiptCount = 0;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (! is_array($record) || ($record['status'] ?? '') !== StewardshipIntegrationLaneService::STATUS_INTEGRATED) {
                continue;
            }

            $commit = (string) data_get($record, 'integration_lane.lane_commit_after', '');
            if ($commit === ''
                || ! $this->isAncestor($repoRoot, $baseBefore, $commit)
                || ! $this->isAncestor($repoRoot, $commit, $laneCommit)
            ) {
                continue;
            }

            $policy = (array) data_get($record, 'governance_report.auto_merge_policy', []);
            $authorized = (bool) ($policy['injected_plan_slice_code_auto_merge_authorized'] ?? false)
                || (bool) ($policy['bounded_packet_code_auto_merge_authorized'] ?? false)
                || (bool) ($policy['factory_scoped_code_auto_merge_authorized'] ?? false);
            $eligible = (bool) data_get($record, 'branch_review_packet.risk_summary.auto_merge_eligible', false)
                || (bool) ($policy['eligible'] ?? false);
            if (! $authorized || ! $eligible) {
                continue;
            }

            foreach ((array) data_get($record, 'governance_report.gitkraken_review_surface.changed_files', []) as $file) {
                if (is_string($file) && trim($file) !== '') {
                    $allowed[trim($file)] = true;
                }
            }
            $receiptCount++;
        }

        $covered = array_values(array_filter($changedFiles, static fn (string $file): bool => isset($allowed[$file])));
        $missing = array_values(array_diff($changedFiles, $covered));

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap783_authorized_lane_scope.v1',
            'status' => $missing === [] && $receiptCount > 0 ? 'authorized' : 'not_authorized',
            'injected_plan_slice_auto_merge' => $missing === [] && $receiptCount > 0,
            'changed_files' => $changedFiles,
            'allowed_files' => array_keys($allowed),
            'covered_files' => $covered,
            'missing_files' => $missing,
            'receipt_count' => $receiptCount,
            'record_path' => $path,
        ];
    }

    private function integrationLaneRecordPath(string $areaId): string
    {
        $dir = $this->storageRootOverride !== null
            ? $this->storageRootOverride.'/integration_lanes'
            : (function_exists('storage_path')
                ? storage_path('atlas/software_company_stewardship/integration_lanes')
                : sys_get_temp_dir().'/atlas/software_company_stewardship/integration_lanes');

        return $dir.DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    private function unsafeLaneRef(string $laneRef): bool
    {
        return ! str_starts_with($laneRef, 'atlas/integration/')
            || str_contains($laneRef, '..')
            || str_contains($laneRef, ' ');
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

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    private function isGitRepo(string $repoRoot): bool
    {
        return $this->git($repoRoot, ['rev-parse', '--is-inside-work-tree'])['ok'] === true;
    }

    private function workingTreeClean(string $repoRoot): bool
    {
        $result = $this->git($repoRoot, ['status', '--porcelain']);

        return $result['ok'] && trim((string) $result['out']) === '';
    }

    private function revParse(string $repoRoot, string $ref): string
    {
        $result = $this->git($repoRoot, ['rev-parse', '--verify', $ref]);

        return $result['ok'] ? trim((string) $result['out']) : '';
    }

    private function isAncestor(string $repoRoot, string $ancestor, string $descendant): bool
    {
        return $this->git($repoRoot, ['merge-base', '--is-ancestor', $ancestor, $descendant])['ok'] === true;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $repoRoot, string $baseRef, string $branchRef): array
    {
        $result = $this->git($repoRoot, ['diff', '--name-only', $baseRef.'..'.$branchRef]);
        if (! $result['ok']) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", (string) $result['out']))));
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $repoRoot, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repoRoot);
        $process->setTimeout(60);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['recorded_at'], $payload['promotion_storage_status'], $payload['promotion_hash']);

        return $payload;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: self::DEFAULT_AREA_ID;

        return trim($slug, '_-') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
