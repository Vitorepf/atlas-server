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
 * AP-782 · Stewardship Integration Lane.
 *
 * Safely advances a visible Atlas integration branch for auto-merge candidates
 * when the operator's primary worktree is dirty. It never mutates main.
 */
final class StewardshipIntegrationLaneService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.integration_lane.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.integration_lane_record.v1';

    public const STATUS_INTEGRATED = 'integrated_to_lane';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipBranchReviewPacketService $reviewPacket,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->mergeGovernor->setStorageRootForTesting($dir !== null ? $dir.'/merge_governor' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/integration_lanes')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/integration_lanes';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function integrate(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $branchRef = trim((string) ($input['branch_ref'] ?? ''));
        $record = (bool) ($input['record'] ?? false);

        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return $this->blocked($areaId, 'repo_root_not_git_repository', 'AP-782 requires a git repo root.', ['repo_root' => $repoRoot]);
        }
        if ($branchRef === '') {
            return $this->blocked($areaId, 'branch_ref_required', 'AP-782 requires a candidate branch ref.', ['repo_root' => $repoRoot]);
        }
        if ($this->revParse($repoRoot, $baseRef) === '') {
            return $this->blocked($areaId, 'base_ref_not_found', 'Base ref was not found.', ['repo_root' => $repoRoot, 'base_ref' => $baseRef]);
        }
        if ($this->revParse($repoRoot, $branchRef) === '') {
            return $this->blocked($areaId, 'branch_ref_not_found', 'Candidate branch ref was not found.', ['repo_root' => $repoRoot, 'branch_ref' => $branchRef]);
        }

        // AP-806: a successive packet depends on prior packets already merged onto the
        // lane. AP-769 eligibility + validation MUST run against the LANE HEAD (which
        // contains those packets), never the bare base — else a packet that builds on a
        // prior one falsely fails validation against a base that lacks it
        // (candidate_not_auto_merge_eligible). The lane-vs-base ancestry check and the
        // actual advance below still use $baseRef, so main is never mutated and the
        // lane stays based on the real base. First packet (no lane yet) => base.
        $laneRef = trim((string) ($input['lane_ref'] ?? '')) ?: $this->defaultLaneRef($areaId, $baseRef);
        $laneExists = ! $this->unsafeRef($laneRef) && $this->revParse($repoRoot, $laneRef) !== '';
        $laneRefresh = [
            'performed' => false,
            'reason' => '',
            'lane_commit_before' => '',
            'lane_commit_after' => '',
        ];

        if ($laneExists && ! $this->isAncestor($repoRoot, $baseRef, $laneRef)) {
            $laneCommitBeforeRefresh = $this->revParse($repoRoot, $laneRef);
            // Lane auto-reconcile (operator mandate 2026-05-31): a behind/diverged
            // lane must be reconciled deterministically here — never spend provider
            // or fake a merge on it. Refresh ONLY when there are no unpromoted lane
            // commits to lose; otherwise BLOCK with lane_reconcile_required.
            $laneOnly = $this->laneOnlyCommitCount($repoRoot, $baseRef, $laneRef);
            $reconcile = (new LaneReconcileDecider())->decide([
                'base_is_ancestor_of_lane' => false, // inside `! isAncestor(base, lane)`
                'lane_is_ancestor_of_base' => $this->isAncestor($repoRoot, $laneRef, $baseRef),
                'lane_only_commit_count' => $laneOnly,
            ]);
            if ($reconcile['action'] === LaneReconcileDecider::ACTION_BLOCK) {
                return $this->blocked($areaId, LaneReconcileDecider::BLOCKER, 'Integration lane diverged from base with unpromoted commits; reconcile required before any provider spend or merge.', [
                    'lane_ref' => $laneRef,
                    'base_ref' => $baseRef,
                    'lane_commit' => $laneCommitBeforeRefresh,
                    'base_commit' => $this->revParse($repoRoot, $baseRef),
                    'lane_reconcile' => $reconcile,
                    'evidence_refs' => [
                        'lane_only_commit_count' => $laneOnly,
                        'lane_state' => $reconcile['lane_state'],
                    ],
                ]);
            }

            $refresh = $this->git($repoRoot, ['branch', '-f', $laneRef, $baseRef]);
            if ($refresh['ok'] !== true) {
                return $this->blocked($areaId, 'integration_lane_refresh_failed', 'Git refused to refresh the stale integration lane to the current base ref.', [
                    'lane_ref' => $laneRef,
                    'base_ref' => $baseRef,
                    'lane_commit' => $laneCommitBeforeRefresh,
                    'base_commit' => $this->revParse($repoRoot, $baseRef),
                    'git_result' => $refresh,
                ]);
            }

            $laneRefresh = [
                'performed' => true,
                'reason' => $reconcile['reason'],
                'lane_state' => $reconcile['lane_state'],
                'lane_commit_before' => $laneCommitBeforeRefresh,
                'lane_commit_after' => $this->revParse($repoRoot, $laneRef),
            ];
        }

        $validationBaseRef = $laneExists ? $laneRef : $baseRef;

        // When the lane already exists, a successive candidate MUST build on it (its
        // delta is what we evaluate against the lane head). Surface the precise
        // "not based on the lane" reason BEFORE the eligibility evaluate — otherwise
        // a divergent candidate would report a generic ineligibility instead.
        if ($laneExists && ! $this->isAncestor($repoRoot, $laneRef, $branchRef)) {
            return $this->blocked($areaId, 'branch_not_based_on_integration_lane', 'Candidate branch cannot fast-forward the integration lane; regenerate/rebase against the lane first.', [
                'lane_ref' => $laneRef,
                'branch_ref' => $branchRef,
                'lane_commit' => $this->revParse($repoRoot, $laneRef),
                'branch_commit' => $this->revParse($repoRoot, $branchRef),
            ]);
        }

        $governance = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $validationBaseRef,
            'branch_ref' => $branchRef,
            'worktree_path' => (string) ($input['worktree_path'] ?? ''),
            'auto_merge_class' => (string) ($input['auto_merge_class'] ?? ''),
            'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            // The lane respects the SAME auto-merge policy as main (code changes
            // need explicit allow_code_auto_merge + passing validation) so the
            // lane never accumulates work that would not be eligible for main.
            // Absent (legacy AP-782 callers) → defaults preserve prior behavior.
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'run_validation' => (bool) ($input['run_validation'] ?? false),
            'test_commands' => (array) ($input['test_commands'] ?? []),
            'merge_target' => 'integration_lane',
            'origin_type' => (string) ($input['origin_type'] ?? ''),
            'bounded_packet_auto_merge' => (bool) ($input['bounded_packet_auto_merge'] ?? false),
            'bounded_packet_allowed_files' => (array) ($input['bounded_packet_allowed_files'] ?? []),
            'injected_plan_slice_auto_merge' => (bool) ($input['injected_plan_slice_auto_merge'] ?? false),
            'injected_plan_slice_allowed_files' => (array) ($input['injected_plan_slice_allowed_files'] ?? []),
            'record_governance' => $record,
        ]);
        $packet = $this->reviewPacket->build([
            'area_id' => $areaId,
            'governance_report' => $governance,
            'queue_context' => [
                'source_ap_contract' => 'AP-782',
                'purpose' => 'integration_lane',
            ],
        ]);

        if (($governance['status'] ?? '') !== StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE) {
            return $this->blocked($areaId, 'candidate_not_auto_merge_eligible', 'Only AP-769 auto_merge_eligible branches may enter the integration lane.', [
                'governance_report' => $governance,
                'branch_review_packet' => $packet,
            ]);
        }

        // $laneRef already computed above (validation base). Just enforce the safe ref.
        if ($this->unsafeRef($laneRef)) {
            return $this->blocked($areaId, 'unsafe_lane_ref', 'Integration lane ref must be an atlas/integration/* branch.', [
                'lane_ref' => $laneRef,
            ]);
        }

        $baseBefore = $this->revParse($repoRoot, $baseRef);
        $laneBefore = $this->revParse($repoRoot, $laneRef);
        if ($laneBefore === '') {
            $created = $this->git($repoRoot, ['branch', $laneRef, $baseRef]);
            if ($created['ok'] !== true) {
                return $this->blocked($areaId, 'integration_lane_create_failed', 'Git refused to create the integration lane.', [
                    'lane_ref' => $laneRef,
                    'git_result' => $created,
                ]);
            }
            $laneBefore = $this->revParse($repoRoot, $laneRef);
        }

        if (! $this->isAncestor($repoRoot, $baseRef, $laneRef)) {
            return $this->blocked($areaId, 'integration_lane_not_based_on_base', 'Existing integration lane is not based on the requested base ref.', [
                'lane_ref' => $laneRef,
                'base_ref' => $baseRef,
                'lane_commit' => $laneBefore,
                'base_commit' => $baseBefore,
                'governance_report' => $governance,
                'branch_review_packet' => $packet,
            ]);
        }
        if (! $this->isAncestor($repoRoot, $laneRef, $branchRef)) {
            return $this->blocked($areaId, 'branch_not_based_on_integration_lane', 'Candidate branch cannot fast-forward the integration lane; regenerate/rebase against the lane first.', [
                'lane_ref' => $laneRef,
                'branch_ref' => $branchRef,
                'lane_commit' => $laneBefore,
                'branch_commit' => $this->revParse($repoRoot, $branchRef),
                'governance_report' => $governance,
                'branch_review_packet' => $packet,
            ]);
        }

        $advanced = $this->git($repoRoot, ['branch', '-f', $laneRef, $branchRef]);
        if ($advanced['ok'] !== true) {
            return $this->blocked($areaId, 'integration_lane_advance_failed', 'Git refused to fast-forward the integration lane ref.', [
                'lane_ref' => $laneRef,
                'branch_ref' => $branchRef,
                'git_result' => $advanced,
                'governance_report' => $governance,
                'branch_review_packet' => $packet,
            ]);
        }

        $baseAfter = $this->revParse($repoRoot, $baseRef);
        $laneAfter = $this->revParse($repoRoot, $laneRef);
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-782',
            'status' => self::STATUS_INTEGRATED,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-780', 'AP-782'],
            'integration_id' => 'sil_'.substr(MissionCanonicalHash::sha256([$areaId, $repoRoot, $baseRef, $branchRef, $laneRef, $laneAfter]), 0, 18),
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
                'base_commit_before' => $baseBefore,
                'base_commit_after' => $baseAfter,
                'base_untouched' => $baseBefore === $baseAfter,
            ],
            'candidate' => [
                'branch_ref' => $branchRef,
                'branch_commit' => $this->revParse($repoRoot, $branchRef),
            ],
            'integration_lane' => [
                'lane_ref' => $laneRef,
                'lane_commit_before' => $laneBefore,
                'lane_commit_after' => $laneAfter,
                'gitkraken_visible' => true,
                'fast_forwarded' => $laneBefore !== $laneAfter,
                'stale_refresh' => $laneRefresh,
            ],
            'governance_report' => $governance,
            'branch_review_packet' => $packet,
            'next_operator_action' => [
                'Review '.$laneRef.' in GitKraken as the safe integration lane.',
                'When '.$baseRef.' is clean and lease-acquired, AP-769/AP-772 can fast-forward '.$baseRef.' to the lane/candidate.',
            ],
            'claim_policy' => [
                'integration_branch_created_or_advanced' => true,
                'base_branch_mutated' => $baseBefore !== $baseAfter,
                'merge_performed_to_base' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'provider_invoked' => false,
                'rebase_performed' => false,
                'force_push_performed' => false,
                'touches_secrets' => false,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['integration_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, $record);
    }

    /**
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['integration_storage_status' => 'projected'];
        }

        $path = $this->recordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['integration_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-782',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'integration_branch_created_or_advanced' => false,
                'base_branch_mutated' => false,
                'merge_performed_to_base' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'provider_invoked' => false,
            ],
            'generated_at' => $this->now(),
        ] + $extra;
    }

    private function defaultLaneRef(string $areaId, string $baseRef): string
    {
        return 'atlas/integration/'.$areaId.'/'.$this->slug(str_replace('/', '_', $baseRef));
    }

    /**
     * Public lane-ref helper (AP-806): the canonical integration lane branch for
     * an area/base. Used by the loop to base sandbox branches on the lane.
     */
    public function laneRefFor(string $areaId, string $baseRef = 'main'): string
    {
        return $this->defaultLaneRef($this->slug($areaId), trim($baseRef) ?: 'main');
    }

    /** Whether the integration lane branch already exists in the repo. */
    public function laneExists(string $repoRoot, string $areaId, string $baseRef = 'main'): bool
    {
        $repoRoot = trim($repoRoot);
        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return false;
        }

        return $this->revParse($repoRoot, $this->laneRefFor($areaId, $baseRef)) !== '';
    }

    /**
     * Read-only AP-782 reconcile preflight for AP-786/AP-790. A stale/diverged
     * lane with unpromoted commits must block BEFORE provider spend; integration()
     * repeats the same guard before mutating the lane.
     *
     * @return array<string,mixed>
     */
    public function reconcileReadinessForSandbox(string $repoRoot, string $areaId, string $baseRef = 'main'): array
    {
        $repoRoot = trim($repoRoot);
        $baseRef = trim($baseRef) ?: 'main';
        $laneRef = $this->laneRefFor($areaId, $baseRef);

        $baseCommit = $repoRoot !== '' && $this->isGitRepo($repoRoot) ? $this->revParse($repoRoot, $baseRef) : '';
        $laneCommit = $repoRoot !== '' && $this->isGitRepo($repoRoot) ? $this->revParse($repoRoot, $laneRef) : '';

        $base = [
            'schema_version' => 'atlas.software_company_stewardship.integration_lane_reconcile_readiness.v1',
            'lane_ref' => $laneRef,
            'base_ref' => $baseRef,
            'base_commit' => $baseCommit,
            'lane_commit' => $laneCommit,
            'provider_spend_allowed' => true,
            'sandbox_base_ref' => $baseRef,
            'blockers' => [],
        ];

        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return array_replace($base, ['status' => 'ready', 'reason' => 'repo_unavailable_for_lane_preflight']);
        }
        if ($baseCommit === '' || $laneCommit === '') {
            return array_replace($base, ['status' => 'ready', 'reason' => $laneCommit === '' ? 'lane_missing' : 'base_missing']);
        }

        $baseIsAncestorOfLane = $this->isAncestor($repoRoot, $baseRef, $laneRef);
        $laneIsAncestorOfBase = $this->isAncestor($repoRoot, $laneRef, $baseRef);
        if ($baseIsAncestorOfLane) {
            return array_replace($base, [
                'status' => 'ready',
                'reason' => $laneIsAncestorOfBase ? 'lane_equals_base' : 'lane_ahead_of_base',
                'sandbox_base_ref' => $laneRef,
            ]);
        }

        $laneOnly = $this->laneOnlyCommitCount($repoRoot, $baseRef, $laneRef);
        $reconcile = (new LaneReconcileDecider())->decide([
            'base_is_ancestor_of_lane' => false,
            'lane_is_ancestor_of_base' => $laneIsAncestorOfBase,
            'lane_only_commit_count' => $laneOnly,
        ]);

        if (($reconcile['action'] ?? '') === LaneReconcileDecider::ACTION_BLOCK) {
            return array_replace($base, [
                'status' => 'blocked',
                'reason' => LaneReconcileDecider::BLOCKER,
                'provider_spend_allowed' => false,
                'blockers' => [LaneReconcileDecider::BLOCKER],
                'lane_reconcile' => $reconcile,
                'evidence_refs' => [
                    'lane_only_commit_count' => $laneOnly,
                    'lane_state' => $reconcile['lane_state'] ?? '',
                ],
            ]);
        }

        return array_replace($base, [
            'status' => 'ready',
            'reason' => (string) ($reconcile['reason'] ?? 'lane_refreshable'),
            'sandbox_base_ref' => $baseRef,
            'lane_reconcile' => $reconcile,
        ]);
    }

    /**
     * Return the safe ref for the next sandbox. Use the integration lane only
     * while it contains the current base; if main has already moved past the
     * lane, generate the next branch from main so AP-782 can refresh the stale
     * lane and still fast-forward it.
     */
    public function laneBaseRefForSandbox(string $repoRoot, string $areaId, string $baseRef = 'main'): string
    {
        $repoRoot = trim($repoRoot);
        $baseRef = trim($baseRef) ?: 'main';
        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return $baseRef;
        }

        $readiness = $this->reconcileReadinessForSandbox($repoRoot, $areaId, $baseRef);

        return (string) ($readiness['sandbox_base_ref'] ?? $baseRef) ?: $baseRef;
    }

    private function unsafeRef(string $ref): bool
    {
        return ! str_starts_with($ref, 'atlas/integration/') || str_contains($ref, '..') || str_contains($ref, ' ');
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

    private function revParse(string $repoRoot, string $ref): string
    {
        $result = $this->git($repoRoot, ['rev-parse', '--verify', $ref]);

        return $result['ok'] ? trim((string) $result['out']) : '';
    }

    /**
     * Commits reachable from the lane but NOT from base — i.e. unpromoted lane
     * work. Right side of `base..lane`. On failure returns a CONSERVATIVE positive
     * count so {@see LaneReconcileDecider} blocks a diverged lane rather than
     * risking a destructive refresh that could discard real commits.
     */
    private function laneOnlyCommitCount(string $repoRoot, string $baseRef, string $laneRef): int
    {
        $result = $this->git($repoRoot, ['rev-list', '--count', $baseRef.'..'.$laneRef]);
        if ($result['ok'] !== true) {
            return 1;
        }
        $count = trim((string) $result['out']);

        return ctype_digit($count) ? (int) $count : 1;
    }

    private function isAncestor(string $repoRoot, string $ancestor, string $descendant): bool
    {
        return $this->git($repoRoot, ['merge-base', '--is-ancestor', $ancestor, $descendant])['ok'] === true;
    }

    /**
     * @param  list<string>  $args
     * @return array<string,mixed>
     */
    private function git(string $cwd, array $args, int $timeout = 30): array
    {
        $process = new Process(array_merge(['git'], $args), $cwd, null, null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
            'cmd' => 'git '.implode(' ', $args),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['recorded_at'], $payload['integration_storage_status'], $payload['integration_hash']);

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
