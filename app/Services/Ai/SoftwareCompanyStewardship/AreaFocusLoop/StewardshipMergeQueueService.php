<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-772 · Stewardship Merge Queue.
 *
 * Sequential enterprise merge train for 24/7 stewardship branches. It composes
 * AP-769 for branch safety and AP-771 for ordering, then re-evaluates each
 * branch against the live base before any optional ff-only auto-merge.
 */
final class StewardshipMergeQueueService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.merge_queue.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.merge_queue_record.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipPriorityEngineService $priorityEngine,
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
            ? storage_path('atlas/software_company_stewardship/merge_queue')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/merge_queue';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $branchRefs = $this->branchRefs($input);
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
                'test_commands' => array_values(array_filter((array) ($input['test_commands'] ?? []), 'is_string')),
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
            'source_ap_contracts' => ['AP-769', 'AP-771', 'AP-772'],
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
            ],
            'branch_count' => count($branchRefs),
            'priority_report' => $priority,
            'planned_order' => $ordered,
            'results' => $results,
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
            'generated_at' => $this->now(),
        ];
        $payload['queue_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, (bool) ($input['record_queue'] ?? false));
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $areaId = $this->slug($areaId ?: self::DEFAULT_AREA_ID);
        $records = [];
        $path = $this->recordPath($areaId);
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === self::RECORD_SCHEMA) {
                    $records[] = $decoded;
                }
            }
        }

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

        return [
            'branch_ref' => (string) data_get($report, 'repo.branch_ref', ''),
            'governance_status' => (string) ($report['status'] ?? 'unknown'),
            'auto_merge_eligible' => (bool) data_get($report, 'auto_merge_policy.eligible', false),
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
            'governance' => $report,
        ];
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
        ];
    }

    /**
     * @return list<string>
     */
    private function branchRefs(array $input): array
    {
        $refs = $input['branch_refs'] ?? $input['branches'] ?? [];
        if (is_string($refs)) {
            $refs = preg_split('/[\s,]+/', $refs) ?: [];
        }
        if (! is_array($refs)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function (mixed $item): string {
            if (is_array($item)) {
                return trim((string) ($item['branch_ref'] ?? $item['branch'] ?? ''));
            }

            return trim((string) $item);
        }, $refs), static fn (string $ref): bool => $ref !== '')));
    }

    private function repoRoot(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }
        if ($candidate === '') {
            $candidate = getcwd() ?: '';
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['queue_storage_status' => 'projected'];
        }

        $path = $this->recordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['queue_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
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
            'generated_at' => $this->now(),
        ];
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

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_:-]+/', '_', $slug) ?: self::DEFAULT_AREA_ID;

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
