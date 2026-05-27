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
final class AreaFocusBranchSandboxMaterializerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_MATERIALIZED = 'materialized';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
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
            return $existing + ['sandbox_storage_status' => 'existing'];
        }

        $git = $this->gitPreflight($repoRoot, $branchName, $baseRef, $worktreePath);
        if ($git['status'] === self::STATUS_BLOCKED) {
            return $this->blocked($areaId, (string) $git['reason'], (string) $git['detail'], $preflight, [
                'sandbox_id' => $sandboxId,
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
        foreach ($areas as $area) {
            $path = $this->sandboxRecordPath($area);
            if (! is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $records[] = $decoded;
                }
            }
        }

        usort($records, static fn (array $a, array $b): int => ((string) ($b['recorded_at'] ?? '')) <=> ((string) ($a['recorded_at'] ?? '')));

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_records.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-756',
            'area_id' => $areaId,
            'sandbox_count' => count($records),
            'sandboxes' => $records,
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

        return [
            'status' => self::STATUS_MATERIALIZED,
            'current_worktree_branch' => trim((string) ($branch['stdout'] ?? '')),
        ];
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
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['sandbox_id'] ?? '') === $sandboxId) {
                return $decoded;
            }
        }

        return null;
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
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['recorded_at'], $copy['sandbox_hash'], $copy['sandbox_storage_status']);

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
