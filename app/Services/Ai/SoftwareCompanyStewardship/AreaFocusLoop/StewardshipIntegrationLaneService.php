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

        $governance = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $baseRef,
            'branch_ref' => $branchRef,
            'auto_merge_class' => (string) ($input['auto_merge_class'] ?? ''),
            'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            // The lane respects the SAME auto-merge policy as main (code changes
            // need explicit allow_code_auto_merge + passing validation) so the
            // lane never accumulates work that would not be eligible for main.
            // Absent (legacy AP-782 callers) → defaults preserve prior behavior.
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'run_validation' => (bool) ($input['run_validation'] ?? false),
            'test_commands' => (array) ($input['test_commands'] ?? []),
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

        $laneRef = trim((string) ($input['lane_ref'] ?? '')) ?: $this->defaultLaneRef($areaId, $baseRef);
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
