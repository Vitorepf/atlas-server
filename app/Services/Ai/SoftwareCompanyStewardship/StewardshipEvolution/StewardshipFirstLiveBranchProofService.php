<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchReviewPacketService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * AP-781 · First Live Branch Proof.
 *
 * Creates one real isolated git worktree, commits one tiny docs-only proof on a
 * visible stewardship branch, then composes AP-769 and AP-780 so GitKraken /
 * Product Mode can review the branch before any merge. It never mutates main.
 */
final class StewardshipFirstLiveBranchProofService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.first_live_branch_proof.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.first_live_branch_proof_record.v1';

    public const STATUS_PROVEN = 'proven';

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

    public function storageDir(?string $repoRoot = null): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        if ($repoRoot !== null && $repoRoot !== '') {
            $parent = dirname($repoRoot);

            return $parent.'/.atlas-stewardship-worktrees';
        }

        return sys_get_temp_dir().'/atlas/software_company_stewardship/live_branch_proofs';
    }

    public function recordPath(string $areaId, ?string $repoRoot = null): string
    {
        return $this->storageDir($repoRoot).DIRECTORY_SEPARATOR.'records'.DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
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
        $record = (bool) ($input['record'] ?? false);

        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return $this->blocked($areaId, 'repo_root_not_git_repository', 'AP-781 requires a git repo root.', ['repo_root' => $repoRoot]);
        }
        if ($this->git($repoRoot, ['rev-parse', '--verify', $baseRef])['ok'] !== true) {
            return $this->blocked($areaId, 'base_ref_not_found', 'Base ref was not found.', ['repo_root' => $repoRoot, 'base_ref' => $baseRef]);
        }

        $proofId = 'flbp_'.substr(MissionCanonicalHash::sha256([
            'AP-781',
            $areaId,
            $repoRoot,
            $baseRef,
            (string) ($input['proof_nonce'] ?? $this->now()),
        ]), 0, 18);
        $branchName = $this->branchName($areaId, $proofId, $input);
        $worktreePath = $this->worktreePath($repoRoot, $proofId, $input);

        if ($this->git($repoRoot, ['rev-parse', '--verify', $branchName])['ok'] === true) {
            return $this->blocked($areaId, 'proof_branch_already_exists', 'The AP-781 proof branch already exists.', [
                'branch_ref' => $branchName,
                'repo_root' => $repoRoot,
            ]);
        }
        if (file_exists($worktreePath)) {
            return $this->blocked($areaId, 'proof_worktree_path_exists', 'The AP-781 proof worktree path already exists.', [
                'worktree_path' => $worktreePath,
                'repo_root' => $repoRoot,
            ]);
        }

        File::ensureDirectoryExists(dirname($worktreePath));
        $created = $this->git($repoRoot, ['worktree', 'add', '-b', $branchName, $worktreePath, $baseRef], 120);
        if ($created['ok'] !== true) {
            return $this->blocked($areaId, 'git_worktree_add_failed', 'Git refused to create the AP-781 worktree.', [
                'branch_ref' => $branchName,
                'worktree_path' => $worktreePath,
                'git_result' => $created,
            ]);
        }

        $proofFile = $this->proofFile($worktreePath, $proofId);
        File::ensureDirectoryExists(dirname($proofFile));
        File::put($proofFile, $this->proofMarkdown($proofId, $areaId, $branchName, $baseRef, $repoRoot));

        $relativeProofFile = $this->relativePath($worktreePath, $proofFile);
        $this->git($worktreePath, ['add', $relativeProofFile], 30);
        $committed = $this->git($worktreePath, [
            '-c', 'user.email=atlas-stewardship@example.test',
            '-c', 'user.name=Atlas Stewardship',
            'commit',
            '-m',
            'Atlas Stewardship first live branch proof',
        ], 120);
        if ($committed['ok'] !== true) {
            return $this->blocked($areaId, 'proof_commit_failed', 'Git refused to commit the AP-781 proof file.', [
                'branch_ref' => $branchName,
                'worktree_path' => $worktreePath,
                'proof_file' => $relativeProofFile,
                'git_result' => $committed,
            ]);
        }

        $branchCommit = trim((string) $this->git($repoRoot, ['rev-parse', $branchName])['out']);
        $mainCommitBefore = trim((string) $this->git($repoRoot, ['rev-parse', $baseRef])['out']);
        $governance = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $baseRef,
            'branch_ref' => $branchName,
            'auto_merge_class' => 'documentation_only',
            'record_governance' => $record,
        ]);
        $packet = $this->reviewPacket->build([
            'area_id' => $areaId,
            'governance_report' => $governance,
            'queue_context' => [
                'source_ap_contract' => 'AP-781',
                'purpose' => 'first_live_branch_proof',
            ],
        ]);
        $mainCommitAfter = trim((string) $this->git($repoRoot, ['rev-parse', $baseRef])['out']);

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-781',
            'status' => self::STATUS_PROVEN,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-780', 'AP-781'],
            'proof_id' => $proofId,
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
                'base_commit_before' => $mainCommitBefore,
                'base_commit_after' => $mainCommitAfter,
                'main_untouched' => $mainCommitBefore === $mainCommitAfter,
            ],
            'branch' => [
                'branch_ref' => $branchName,
                'branch_commit' => $branchCommit,
                'worktree_path' => $worktreePath,
                'proof_file' => $relativeProofFile,
                'gitkraken_visible' => true,
                'operator_review_required_before_merge' => true,
            ],
            'governance_report' => $governance,
            'branch_review_packet' => $packet,
            'next_operator_action' => [
                'Open GitKraken and review branch '.$branchName.' against '.$baseRef.'.',
                'Approve, reject, request changes, or run AP-769/AP-772 policy-gated ff-only merge.',
            ],
            'claim_policy' => [
                'real_git_branch_created' => true,
                'real_git_worktree_created' => true,
                'real_commit_created' => true,
                'provider_invoked' => false,
                'merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'main_mutated' => $mainCommitBefore !== $mainCommitAfter,
                'touches_secrets' => false,
            ],
            'generated_at' => $this->now(),
        ];
        $receipt['proof_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($receipt));

        return $this->maybeRecord($areaId, $repoRoot, $receipt, $record);
    }

    /**
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, string $repoRoot, array $receipt, bool $record): array
    {
        if (! $record) {
            return $receipt + ['proof_storage_status' => 'projected'];
        }

        $path = $this->recordPath($areaId, $repoRoot);
        File::ensureDirectoryExists(dirname($path));
        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $receipt;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['proof_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-781',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'real_git_branch_created' => false,
                'real_git_worktree_created' => false,
                'real_commit_created' => false,
                'merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
            ],
            'generated_at' => $this->now(),
        ] + $extra;
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

    private function branchName(string $areaId, string $proofId, array $input): string
    {
        $branch = trim((string) ($input['branch_ref'] ?? $input['branch_name'] ?? ''));
        if ($branch !== '') {
            return $branch;
        }

        return 'atlas/area-focus/'.$areaId.'/live-proof-'.substr($proofId, -8);
    }

    private function worktreePath(string $repoRoot, string $proofId, array $input): string
    {
        $path = trim((string) ($input['worktree_path'] ?? ''));
        if ($path !== '') {
            return $path;
        }

        return $this->storageDir($repoRoot).DIRECTORY_SEPARATOR.'worktrees'.DIRECTORY_SEPARATOR.$proofId;
    }

    private function proofFile(string $worktreePath, string $proofId): string
    {
        return $worktreePath.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'ap'.DIRECTORY_SEPARATOR.$proofId.'-first-live-branch-proof.md';
    }

    private function proofMarkdown(string $proofId, string $areaId, string $branchName, string $baseRef, string $repoRoot): string
    {
        return "# AP-781 First Live Branch Proof\n\n"
            ."- proof_id: `{$proofId}`\n"
            ."- area_id: `{$areaId}`\n"
            ."- branch_ref: `{$branchName}`\n"
            ."- base_ref: `{$baseRef}`\n"
            ."- repo_root_hash: `".hash('sha256', $repoRoot)."`\n"
            ."- claim: real branch, real worktree, real commit, no merge, no push, no deploy.\n";
    }

    private function relativePath(string $root, string $path): string
    {
        return ltrim(str_replace(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, '', $path), DIRECTORY_SEPARATOR);
    }

    private function isGitRepo(string $repoRoot): bool
    {
        return $this->git($repoRoot, ['rev-parse', '--is-inside-work-tree'])['ok'] === true;
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
        unset($payload['generated_at'], $payload['recorded_at'], $payload['proof_storage_status'], $payload['proof_hash']);

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
