<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-772 · Stewardship Merge Queue.
 *
 * Sequential enterprise merge train for 24/7 stewardship branches. It composes
 * AP-769 for branch safety, AP-780 for operator review packets, and AP-771
 * for ordering, then re-evaluates each branch against the live base before any
 * optional ff-only auto-merge.
 */
final class StewardshipMergeQueueService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.merge_queue.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.merge_queue_record.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const REPLENISHMENT_SCHEMA = 'atlas.software_company_stewardship.merge_queue_replenishment.v1';

    public const DEFAULT_MAX_REPLENISH_BRANCHES = 5;

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipPriorityEngineService $priorityEngine,
        private readonly StewardshipRepoMergeLeaseService $mergeLease,
        private readonly StewardshipBranchReviewPacketService $branchReviewPacket,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->mergeGovernor->setStorageRootForTesting($dir !== null ? $dir.'/merge_governor' : null);
        $this->mergeLease->setStorageRootForTesting($dir !== null ? $dir.'/repo_merge_lease' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/merge_queue')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/merge_queue';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::areaRefToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $areaId = AreaFocusSlugNormalizer::areaRefToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $repoRoot = AreaFocusLoopPayloadNormalizer::repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $replenishment = null;
        $branchRefs = $this->branchRefs($input);
        if ($branchRefs === [] && $this->terminalBacklogReplenishmentActive($input)) {
            $replenishment = $this->replenishExecutableAfterTerminalStarvation($input);
            $branchRefs = (array) ($replenishment['branch_refs'] ?? []);
        }
        if ($branchRefs === []) {
            return $this->blocked($areaId, 'branch_refs_required', 'AP-772 requires at least one branch ref.');
        }

        $initial = [];
        foreach ($branchRefs as $branchRef) {
            $report = $this->mergeGovernor->evaluate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'branch_ref' => $branchRef,
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            ]);
            $initial[] = $this->queueItem($report);
        }

        $priority = $this->priorityEngine->rank([
            'area_id' => $areaId,
            'candidates' => array_map(static fn (array $item): array => $item['priority_candidate'], $initial),
        ]);
        $ordered = $this->orderByPriority($initial, (array) ($priority['ranked_candidates'] ?? []));

        $executeQueue = (bool) ($input['execute_queue'] ?? false);
        $autoMerge = (bool) ($input['auto_merge'] ?? false);
        $lease = null;
        if ($executeQueue) {
            $lease = $this->mergeLease->acquire([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'owner' => (string) ($input['lease_owner'] ?? $input['runner_id'] ?? 'merge_queue_'.$areaId),
                'ttl_seconds' => (int) ($input['lease_ttl_seconds'] ?? 1800),
            ]);
            if (($lease['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_BLOCKED) {
                return $this->blocked($areaId, (string) ($lease['reason'] ?? 'repo_merge_lease_blocked'), (string) ($lease['detail'] ?? 'AP-775 blocked merge queue execution.'), [
                    'repo' => ['repo_root' => $repoRoot, 'repo_root_hash' => hash('sha256', $repoRoot), 'base_ref' => $baseRef],
                    'branch_count' => count($branchRefs),
                    'repo_merge_lease' => $lease,
                ]);
            }
        }
        $results = [];
        foreach ($ordered as $item) {
            $branchRef = (string) data_get($item, 'governance.repo.branch_ref', '');
            if ($branchRef === '') {
                $results[] = $item + ['queue_action' => 'blocked_missing_branch_ref'];

                continue;
            }

            if (! $executeQueue || ! $autoMerge) {
                $results[] = $item + ['queue_action' => 'planned_review_or_manual_merge'];

                continue;
            }

            $live = $this->mergeGovernor->evaluate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'branch_ref' => $branchRef,
                'auto_merge' => true,
                'execute_merge' => true,
                'auto_merge_class' => (string) data_get($item, 'governance.classification.operator_declared_class', ''),
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                'run_validation' => (bool) ($input['run_validation'] ?? false),
                'test_commands' => AreaFocusStringListNormalizer::coercedStringValues($input['test_commands'] ?? []),
                'record_governance' => (bool) ($input['record_governance'] ?? false),
            ]);

            $results[] = $this->queueItem($live) + [
                'queue_action' => ($live['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED
                    ? 'auto_merged_ff_only'
                    : 'stopped_or_review_required_after_live_recheck',
            ];
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-772',
            'status' => $executeQueue ? self::STATUS_EXECUTED : self::STATUS_READY,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-771', 'AP-772', 'AP-775', 'AP-780'],
            'queue_id' => 'smq_'.substr(MissionCanonicalHash::sha256([$areaId, $repoRoot, $baseRef, $branchRefs, $executeQueue, $autoMerge]), 0, 18),
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
            ],
            'queue_policy' => [
                'sequential_live_recheck_before_each_merge' => true,
                'auto_merge_requested' => $autoMerge,
                'execute_queue' => $executeQueue,
                'merge_strategy' => 'ff_only_via_ap769',
                'parallel_merges_allowed' => false,
                'repo_merge_lease_required_for_execution' => true,
            ],
            'repo_merge_lease' => $lease,
            'branch_count' => count($branchRefs),
            'priority_report' => $priority,
            'planned_order' => $ordered,
            'results' => $results,
            'branch_review_packets' => $this->branchReviewPackets($results),
            'summary' => $this->summary($results),
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'parallel_merge_performed' => false,
                'ff_only_merges_performed' => count(array_filter($results, static fn (array $r): bool => (string) ($r['queue_action'] ?? '') === 'auto_merged_ff_only')),
                'deploys' => false,
                'touches_secrets' => false,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        if ($replenishment !== null) {
            $payload['merge_queue_replenishment'] = [
                'active' => true,
                'terminal_backlog_state_hash' => (string) ($replenishment['terminal_backlog_state_hash'] ?? ''),
                'terminal_backlog_rejection_reason_count' => (int) ($replenishment['terminal_backlog_rejection_reason_count'] ?? 0),
                'discovered_branch_count' => (int) ($replenishment['discovered_branch_count'] ?? 0),
                'executable_branch_count' => (int) ($replenishment['executable_branch_count'] ?? 0),
                'sources' => (array) ($replenishment['sources'] ?? []),
            ];
        }
        $payload['queue_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        if ($executeQueue && is_array($lease) && ($lease['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_ACQUIRED) {
            $payload['repo_merge_lease_release'] = $this->mergeLease->release([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => $baseRef,
                'owner' => (string) ($lease['owner'] ?? ''),
                'release_reason' => 'merge_queue_finished',
            ]);
        }

        return $this->maybeRecord($areaId, $payload, (bool) ($input['record_queue'] ?? false));
    }

    /**
     * Materialize bounded merge-queue work after AP-790 terminal starvation so
     * the 24h loop can keep advancing instead of stopping at
     * no_candidate_with_allowed_files.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function replenishExecutableAfterTerminalStarvation(array $input): array
    {
        $areaId = AreaFocusSlugNormalizer::areaRefToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $repoRoot = AreaFocusLoopPayloadNormalizer::repoRoot($input);
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $rejectionReasons = array_values(array_filter(
            (array) ($input['terminal_backlog_rejection_reasons'] ?? []),
            'is_string',
        ));
        $maxBranches = max(1, (int) ($input['max_replenish_branches'] ?? self::DEFAULT_MAX_REPLENISH_BRANCHES));

        if (! $this->terminalBacklogReplenishmentActive($input)) {
            return [
                'schema_version' => self::REPLENISHMENT_SCHEMA,
                'ap_contract' => 'AP-772',
                'status' => self::STATUS_BLOCKED,
                'area_id' => $areaId,
                'reason' => 'terminal_backlog_context_required',
                'detail' => 'AP-772 merge-queue replenishment requires terminal_backlog_state_hash or terminal_backlog_rejection_reasons.',
                'terminal_backlog_replenishment' => false,
                'branch_refs' => [],
                'generated_at' => AreaFocusUtcClock::atomNow(),
            ];
        }

        $sources = [];
        $discovered = [];
        foreach ($this->branchRefsFromQueueRecords($areaId) as $branchRef) {
            $discovered[$branchRef] = 'queue_record';
        }
        if ($discovered !== []) {
            $sources[] = 'queue_records';
        }

        if ($repoRoot !== '' && is_dir($repoRoot.'/.git')) {
            foreach ($this->localStewardshipBranches($repoRoot, $areaId) as $branchRef) {
                $discovered[$branchRef] = $discovered[$branchRef] ?? 'local_git';
            }
            if ($discovered !== []) {
                $sources[] = 'local_git';
            }
        }

        $branchRefs = array_slice(array_keys($discovered), 0, $maxBranches);
        $executableBranchRefs = [];
        if ($repoRoot !== '' && is_dir($repoRoot.'/.git') && $branchRefs !== []) {
            $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
            foreach ($branchRefs as $branchRef) {
                $report = $this->mergeGovernor->evaluate([
                    'area_id' => $areaId,
                    'repo_root' => $repoRoot,
                    'base_ref' => $baseRef,
                    'branch_ref' => $branchRef,
                    'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                ]);
                $status = (string) ($report['status'] ?? '');
                if ($status !== StewardshipBranchMergeGovernorService::STATUS_BLOCKED) {
                    $executableBranchRefs[] = $branchRef;
                }
            }
        } else {
            $executableBranchRefs = $branchRefs;
        }

        return [
            'schema_version' => self::REPLENISHMENT_SCHEMA,
            'ap_contract' => 'AP-772',
            'status' => $executableBranchRefs !== [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'terminal_backlog_replenishment' => true,
            'terminal_backlog_state_hash' => $stateHash,
            'terminal_backlog_rejection_reasons' => $rejectionReasons,
            'terminal_backlog_rejection_reason_count' => count($rejectionReasons),
            'max_replenish_branches' => $maxBranches,
            'discovered_branch_count' => count($discovered),
            'executable_branch_count' => count($executableBranchRefs),
            'branch_refs' => $executableBranchRefs,
            'branch_sources' => array_intersect_key($discovered, array_flip($executableBranchRefs)),
            'sources' => AreaFocusStringListNormalizer::uniqueStringValues($sources),
            'reason' => $executableBranchRefs === [] ? 'no_executable_branches_after_replenishment' : '',
            'detail' => $executableBranchRefs === []
                ? 'AP-772 replenishment found branches but none passed live merge governance.'
                : 'AP-772 replenished bounded merge-queue work after terminal starvation.',
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $areaId = AreaFocusSlugNormalizer::areaRefToken($areaId ?: self::DEFAULT_AREA_ID, self::DEFAULT_AREA_ID);
        $records = AreaFocusJsonlReader::rowsWithSchemaVersion($this->recordPath($areaId), self::RECORD_SCHEMA);

        return [
            'schema_version' => 'atlas.software_company_stewardship.merge_queue_records.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-772',
            'area_id' => $areaId,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function queueItem(array $report): array
    {
        $changed = array_values((array) data_get($report, 'gitkraken_review_surface.changed_files', []));
        $kind = (string) data_get($report, 'classification.kind', 'code_or_mixed');
        $packet = $this->branchReviewPacket->build([
            'area_id' => (string) ($report['area_id'] ?? self::DEFAULT_AREA_ID),
            'governance_report' => $report,
            'queue_context' => [
                'source_ap_contract' => 'AP-772',
                'queue_phase' => data_get($report, 'repo.branch_commit') ? 'governance_snapshot' : 'governance_unknown',
            ],
        ]);

        return [
            'branch_ref' => (string) data_get($report, 'repo.branch_ref', ''),
            'governance_status' => (string) ($report['status'] ?? 'unknown'),
            'auto_merge_eligible' => (bool) data_get($report, 'auto_merge_policy.eligible', false),
            'branch_review_packet_status' => (string) ($packet['status'] ?? 'unknown'),
            'reviewable_commit_count' => (int) data_get($report, 'gitkraken_review_surface.reviewable_commit_count', 0),
            'changed_file_count' => count($changed),
            'priority_candidate' => [
                'id' => (string) data_get($report, 'repo.branch_ref', ''),
                'branch_ref' => (string) data_get($report, 'repo.branch_ref', ''),
                'title' => (string) data_get($report, 'repo.branch_ref', ''),
                'kind' => match ($kind) {
                    'documentation_only' => 'doc',
                    'tests_only', 'docs_and_tests' => 'test',
                    default => 'gap',
                },
                'severity' => $report['status'] === StewardshipBranchMergeGovernorService::STATUS_BLOCKED ? 'high' : 'medium',
                'owner_candidate' => 'agentic_engineering_os',
                'confidence' => data_get($report, 'merge_conflict_check.clean') ? 0.88 : 0.35,
                'affected_files' => $changed,
                'merge_conflict_detected' => ! (bool) data_get($report, 'merge_conflict_check.clean', false),
            ],
            'branch_review_packet' => $packet,
            'governance' => $report,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @return list<array<string,mixed>>
     */
    private function branchReviewPackets(array $results): array
    {
        $packets = [];
        foreach ($results as $result) {
            $packet = (array) ($result['branch_review_packet'] ?? []);
            if ((string) ($packet['schema_version'] ?? '') === StewardshipBranchReviewPacketService::PACKET_SCHEMA) {
                $packets[] = $packet;
            }
        }

        return $packets;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $ranked
     * @return list<array<string,mixed>>
     */
    private function orderByPriority(array $items, array $ranked): array
    {
        $byBranch = [];
        foreach ($items as $item) {
            $byBranch[(string) ($item['branch_ref'] ?? '')] = $item;
        }

        $ordered = [];
        foreach ($ranked as $rank) {
            $branch = (string) ($rank['candidate_id'] ?? '');
            if (isset($byBranch[$branch])) {
                $ordered[] = $byBranch[$branch] + [
                    'priority_score' => (float) ($rank['priority_score'] ?? 0),
                    'priority_band' => (string) ($rank['priority_band'] ?? ''),
                    'rank' => (int) ($rank['rank'] ?? 0),
                    'autonomy_hint' => (string) ($rank['autonomy_hint'] ?? ''),
                ];
            }
        }

        return $ordered;
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @return array<string,int>
     */
    private function summary(array $results): array
    {
        return [
            'auto_merged' => count(array_filter($results, static fn (array $r): bool => (string) ($r['queue_action'] ?? '') === 'auto_merged_ff_only')),
            'review_required' => count(array_filter($results, static fn (array $r): bool => (string) ($r['governance_status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED)),
            'blocked' => count(array_filter($results, static fn (array $r): bool => (string) ($r['governance_status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED)),
            'planned' => count(array_filter($results, static fn (array $r): bool => (string) ($r['queue_action'] ?? '') === 'planned_review_or_manual_merge')),
            'branch_review_packets' => count($this->branchReviewPackets($results)),
            'auto_merge_candidate_packets' => count(array_filter($results, static fn (array $r): bool => (string) ($r['branch_review_packet_status'] ?? '') === StewardshipBranchReviewPacketService::STATUS_AUTO_MERGE_CANDIDATE)),
            'blocked_packets' => count(array_filter($results, static fn (array $r): bool => (string) ($r['branch_review_packet_status'] ?? '') === StewardshipBranchReviewPacketService::STATUS_BLOCKED)),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalBacklogReplenishmentActive(array $input): bool
    {
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return $stateHash !== '' || $reasons !== [];
    }

    /**
     * @return list<string>
     */
    private function branchRefsFromQueueRecords(string $areaId): array
    {
        $refs = [];
        foreach ((array) ($this->listRecords($areaId)['records'] ?? []) as $record) {
            foreach (['planned_order', 'results'] as $key) {
                foreach ((array) ($record[$key] ?? []) as $item) {
                    $branchRef = trim((string) ($item['branch_ref'] ?? data_get($item, 'governance.repo.branch_ref', '')));
                    if ($branchRef !== '') {
                        $refs[] = $branchRef;
                    }
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($refs);
    }

    /**
     * @return list<string>
     */
    private function localStewardshipBranches(string $repoRoot, string $areaId): array
    {
        $prefixes = [
            'atlas/area-focus/',
            'atlas/integration/'.$areaId.'/',
        ];
        $refs = [];
        foreach ($prefixes as $prefix) {
            foreach (AreaFocusBranchRefNormalizer::localBranches($repoRoot, $prefix) as $branchRef) {
                $refs[] = $branchRef;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($refs);
    }

    /**
     * @return list<string>
     */
    private function branchRefs(array $input): array
    {
        return AreaFocusBranchRefNormalizer::fromInput($input);
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
            'queue_storage_status',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-772',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ] + $extra;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['queue_hash'], $copy['generated_at'], $copy['recorded_at'], $copy['queue_storage_status']);

        return $copy;
    }
}
