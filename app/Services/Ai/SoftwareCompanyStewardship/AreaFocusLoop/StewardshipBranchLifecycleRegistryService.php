<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-770 · Branch lifecycle registry.
 *
 * Pre-git collision guard for the stewardship loop. AP-756 creates the physical
 * branch/worktree; AP-769 governs merge. AP-770 reserves the intended branch
 * identity before AP-756 mutates git, so parallel loops cannot accidentally
 * fight over the same branch name, repo and base line.
 */
final class StewardshipBranchLifecycleRegistryService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.branch_lifecycle_registry.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.branch_lifecycle_registry_record.v1';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_MATERIALIZED = 'materialized';

    public const STATUS_MERGED = 'merged';

    public const STATUS_RELEASED = 'released';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /** @var list<string> */
    private const ACTIVE_STATUSES = [self::STATUS_RESERVED, self::STATUS_MATERIALIZED];

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
            ? storage_path('atlas/software_company_stewardship/branch_lifecycle_registry')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/branch_lifecycle_registry';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function reserve(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $branchName = trim((string) ($input['branch_name'] ?? $input['branch_ref'] ?? ''));
        if ($branchName === '') {
            return $this->blocked($areaId, 'branch_name_required', 'AP-770 requires a branch name before AP-756 materializes git.');
        }

        $repoRoot = $this->repoRoot($input);
        $repoRootHash = hash('sha256', $repoRoot);
        $baseRef = trim((string) ($input['base_ref'] ?? 'HEAD')) ?: 'HEAD';
        $handoffHash = trim((string) ($input['handoff_hash'] ?? $input['target_handoff_hash'] ?? ''));
        $sandboxId = trim((string) ($input['sandbox_id'] ?? ''));
        $record = (bool) ($input['record_branch_registry'] ?? false);
        $status = (string) ($input['lifecycle_status'] ?? self::STATUS_RESERVED);
        if (! in_array($status, [self::STATUS_RESERVED, self::STATUS_MATERIALIZED, self::STATUS_MERGED, self::STATUS_RELEASED], true)) {
            $status = self::STATUS_RESERVED;
        }

        $branchKey = $this->branchKey($repoRootHash, $branchName);
        $registryId = $this->registryId($areaId, $branchKey, $handoffHash, $sandboxId, $status);
        $existing = $this->findRecord($this->recordPath($areaId), $registryId);
        if ($existing !== null) {
            return $existing + ['registry_storage_status' => 'existing'];
        }

        $collision = $this->activeCollision($areaId, $branchKey, $registryId, $handoffHash, $sandboxId);
        if ($collision !== null) {
            return $this->blocked($areaId, 'branch_lifecycle_collision', 'Another active stewardship cycle already reserved this branch identity.', [
                'registry_id' => $registryId,
                'branch_key' => $branchKey,
                'colliding_registry_id' => (string) ($collision['registry_id'] ?? ''),
                'colliding_handoff_hash' => (string) data_get($collision, 'source_refs.handoff_hash', ''),
                'colliding_sandbox_id' => (string) data_get($collision, 'source_refs.sandbox_id', ''),
            ]);
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-770',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-756', 'AP-769', 'AP-770'],
            'registry_id' => $registryId,
            'branch_key' => $branchKey,
            'branch_identity' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => $repoRootHash,
                'base_ref' => $baseRef,
                'branch_name' => $branchName,
            ],
            'source_refs' => [
                'handoff_hash' => $handoffHash,
                'handoff_id' => (string) ($input['handoff_id'] ?? ''),
                'work_order_id' => (string) ($input['work_order_id'] ?? ''),
                'work_order_hash' => (string) ($input['work_order_hash'] ?? ''),
                'sandbox_id' => $sandboxId,
                'owner' => (string) ($input['owner'] ?? ''),
            ],
            'lifecycle' => [
                'state' => $status,
                'active' => in_array($status, self::ACTIVE_STATUSES, true),
                'created_at' => $this->now(),
                'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400),
            ],
            'collision_guard' => [
                'active_collision_found' => false,
                'active_statuses' => self::ACTIVE_STATUSES,
            ],
            'record_registry_requested' => $record,
            'blockers' => [],
            'next_actions' => [
                'AP-756 may materialize this branch only while this AP-770 reservation remains the active owner.',
                'AP-769 must govern merge before this lifecycle can close as merged or released.',
            ],
            'claim_policy' => [
                'creates_branch' => false,
                'creates_worktree' => false,
                'reserves_branch_identity' => true,
                'prevents_parallel_branch_collision' => true,
                'merge_performed' => false,
                'deploys' => false,
                'touches_secrets' => false,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['registry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, $record);
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $areaId = $this->slug($areaId ?: self::DEFAULT_AREA_ID);
        $records = $this->recordsForArea($areaId);

        return [
            'schema_version' => 'atlas.software_company_stewardship.branch_lifecycle_registry_records.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-770',
            'area_id' => $areaId,
            'record_count' => count($records),
            'active_record_count' => $this->activeRecordCount($records),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function transition(array $input): array
    {
        $status = (string) ($input['lifecycle_status'] ?? $input['status'] ?? '');
        if (! in_array($status, [self::STATUS_MERGED, self::STATUS_RELEASED, self::STATUS_MATERIALIZED], true)) {
            return $this->blocked(
                $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)),
                'unsupported_lifecycle_transition',
                'AP-770 transition supports materialized, merged or released lifecycle states.',
            );
        }

        return $this->reserve($input + [
            'lifecycle_status' => $status,
            'record_branch_registry' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-770',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-770 branch lifecycle blocker before AP-756 materializes any branch/worktree.'],
            'claim_policy' => [
                'creates_branch' => false,
                'creates_worktree' => false,
                'reserves_branch_identity' => false,
                'prevents_parallel_branch_collision' => true,
                'merge_performed' => false,
                'deploys' => false,
                'touches_secrets' => false,
            ],
            'generated_at' => $this->now(),
        ] + $extra;
        $payload['registry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['registry_storage_status' => 'projected'];
        }

        $path = $this->recordPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['registry_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['registry_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;

        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['registry_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $registryId): ?array
    {
        if ($registryId === '' || ! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)
                && (string) ($decoded['schema_version'] ?? '') === self::RECORD_SCHEMA
                && (string) ($decoded['registry_id'] ?? '') === $registryId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recordsForArea(string $areaId): array
    {
        $path = $this->recordPath($areaId);
        $records = [];
        if (! is_file($path)) {
            return [];
        }

        foreach ((file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $index => $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === self::RECORD_SCHEMA) {
                $decoded['record_sequence'] = $index;
                $records[] = $decoded;
            }
        }

        usort($records, static function (array $a, array $b): int {
            return (((string) ($b['recorded_at'] ?? '')) <=> ((string) ($a['recorded_at'] ?? '')))
                ?: (((int) ($b['record_sequence'] ?? 0)) <=> ((int) ($a['record_sequence'] ?? 0)));
        });

        return $records;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeCollision(string $areaId, string $branchKey, string $registryId, string $handoffHash, string $sandboxId): ?array
    {
        foreach ($this->latestByBranchKey($this->recordsForArea($areaId)) as $record) {
            if ((string) ($record['branch_key'] ?? '') !== $branchKey) {
                continue;
            }
            if (! in_array((string) ($record['status'] ?? ''), self::ACTIVE_STATUSES, true)) {
                continue;
            }
            if ((string) ($record['registry_id'] ?? '') === $registryId) {
                continue;
            }

            $sameOwner = $handoffHash !== '' && $handoffHash === (string) data_get($record, 'source_refs.handoff_hash', '');
            $sameSandbox = $sandboxId !== '' && $sandboxId === (string) data_get($record, 'source_refs.sandbox_id', '');
            if (! $sameOwner && ! $sameSandbox) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function activeRecordCount(array $records): int
    {
        return count(array_filter($this->latestByBranchKey($records), static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), self::ACTIVE_STATUSES, true)));
    }

    /**
     * Records are already newest-first, so the first event per branch identity
     * is the lifecycle state that currently governs collisions and WIP counts.
     *
     * @param  list<array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function latestByBranchKey(array $records): array
    {
        $latest = [];
        foreach ($records as $record) {
            $key = (string) ($record['branch_key'] ?? '');
            if ($key !== '' && ! isset($latest[$key])) {
                $latest[$key] = $record;
            }
        }

        return array_values($latest);
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

    private function branchKey(string $repoRootHash, string $branchName): string
    {
        return hash('sha256', $repoRootHash.'|'.$branchName);
    }

    private function registryId(string $areaId, string $branchKey, string $handoffHash, string $sandboxId, string $status): string
    {
        return 'afblr_'.substr(MissionCanonicalHash::sha256([
            'AP-770',
            $areaId,
            $branchKey,
            $handoffHash,
            $sandboxId,
            $status,
        ]), 0, 20);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['registry_hash'], $copy['generated_at'], $copy['recorded_at'], $copy['registry_storage_status']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_:-]+/', '_', $slug) ?: 'agentic_engineering_os';

        return trim($slug, '_') ?: 'agentic_engineering_os';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
