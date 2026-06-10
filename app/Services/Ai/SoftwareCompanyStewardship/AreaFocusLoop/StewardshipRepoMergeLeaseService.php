<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-775 · Stewardship Repo Merge Lease.
 *
 * Durable repo/base lease for 24/7 merge queue execution. It prevents two
 * stewardship runners from operating the same repository line concurrently.
 * It writes only append-only lease records; it never touches git history.
 */
final class StewardshipRepoMergeLeaseService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.repo_merge_lease.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.repo_merge_lease_record.v1';

    public const STATUS_ACQUIRED = 'acquired';

    public const STATUS_RELEASED = 'released';

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
            ? storage_path('atlas/software_company_stewardship/repo_merge_lease')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/repo_merge_lease';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function acquire(array $input): array
    {
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $repoRoot = AreaFocusLoopPayloadNormalizer::repoRoot($input);
        if ($repoRoot === '') {
            return $this->blocked($areaId, 'repo_root_required', 'AP-775 requires repo_root to acquire a merge lease.');
        }

        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $owner = trim((string) ($input['owner'] ?? $input['runner_id'] ?? ''));
        if ($owner === '') {
            return $this->blocked($areaId, 'lease_owner_required', 'AP-775 requires owner or runner_id.');
        }

        $ttlSeconds = max(60, (int) ($input['ttl_seconds'] ?? 1800));
        $repoRootHash = hash('sha256', $repoRoot);
        $leaseKey = $this->leaseKey($repoRootHash, $baseRef);
        $existing = $this->activeLease($areaId, $leaseKey);
        if ($existing !== null && (string) ($existing['owner'] ?? '') !== $owner) {
            return $this->blocked($areaId, 'active_merge_lease_exists', 'Another runner owns the repo/base merge lease.', [
                'lease_key' => $leaseKey,
                'active_lease' => $existing,
            ]);
        }

        $leaseId = 'rml_'.substr(MissionCanonicalHash::sha256([$areaId, $leaseKey, $owner]), 0, 18);
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-775',
            'status' => self::STATUS_ACQUIRED,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-772', 'AP-775'],
            'lease_id' => $leaseId,
            'lease_key' => $leaseKey,
            'owner' => $owner,
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => $repoRootHash,
                'base_ref' => $baseRef,
            ],
            'lease' => [
                'state' => self::STATUS_ACQUIRED,
                'acquired_at' => AreaFocusUtcClock::atomNow(),
                'ttl_seconds' => $ttlSeconds,
                'expires_at' => AreaFocusUtcClock::atomNow(max(0, $ttlSeconds)),
                'reentrant_for_same_owner' => $existing !== null,
            ],
            'claim_policy' => $this->claimPolicy(true),
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $payload['lease_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->record($areaId, $payload);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function release(array $input): array
    {
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $repoRoot = AreaFocusLoopPayloadNormalizer::repoRoot($input);
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $owner = trim((string) ($input['owner'] ?? $input['runner_id'] ?? ''));
        if ($repoRoot === '' || $owner === '') {
            return $this->blocked($areaId, 'repo_root_and_owner_required', 'AP-775 release requires repo_root and owner.');
        }

        $repoRootHash = hash('sha256', $repoRoot);
        $leaseKey = $this->leaseKey($repoRootHash, $baseRef);
        $active = $this->activeLease($areaId, $leaseKey);
        if ($active === null) {
            return $this->blocked($areaId, 'no_active_merge_lease', 'No active lease exists for this repo/base.', ['lease_key' => $leaseKey]);
        }
        if ((string) ($active['owner'] ?? '') !== $owner) {
            return $this->blocked($areaId, 'lease_owner_mismatch', 'Only the active lease owner can release it.', [
                'lease_key' => $leaseKey,
                'active_lease' => $active,
            ]);
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-775',
            'status' => self::STATUS_RELEASED,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-772', 'AP-775'],
            'lease_id' => (string) ($active['lease_id'] ?? ''),
            'lease_key' => $leaseKey,
            'owner' => $owner,
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => $repoRootHash,
                'base_ref' => $baseRef,
            ],
            'lease' => [
                'state' => self::STATUS_RELEASED,
                'released_at' => AreaFocusUtcClock::atomNow(),
                'release_reason' => (string) ($input['release_reason'] ?? 'operator_or_runner_release'),
            ],
            'claim_policy' => $this->claimPolicy(false),
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $payload['lease_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->record($areaId, $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $areaId = AreaFocusSlugNormalizer::lowerFileToken($areaId ?: self::DEFAULT_AREA_ID, self::DEFAULT_AREA_ID);
        $records = $this->records($areaId);
        $active = [];
        foreach ($records as $record) {
            $key = (string) ($record['lease_key'] ?? '');
            if ($key !== '' && $this->recordIsActive($record)) {
                $active[$key] = $this->leaseSummary($record);
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.repo_merge_lease_records.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-775',
            'area_id' => $areaId,
            'record_count' => count($records),
            'active_lease_count' => count($active),
            'active_leases' => array_values($active),
            'records' => $records,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeLease(string $areaId, string $leaseKey): ?array
    {
        $records = array_reverse($this->records($areaId));
        foreach ($records as $record) {
            if ((string) ($record['lease_key'] ?? '') !== $leaseKey) {
                continue;
            }
            if ((string) ($record['status'] ?? '') === self::STATUS_RELEASED) {
                return null;
            }
            if ($this->recordIsActive($record)) {
                return $this->leaseSummary($record);
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function records(string $areaId): array
    {
        return AreaFocusJsonlReader::rowsWithSchemaVersion($this->recordPath($areaId), self::RECORD_SCHEMA);
    }

    private function recordIsActive(array $record): bool
    {
        if ((string) ($record['status'] ?? '') !== self::STATUS_ACQUIRED) {
            return false;
        }
        $expiresAt = (string) data_get($record, 'lease.expires_at', '');
        if ($expiresAt === '') {
            return false;
        }

        return strtotime($expiresAt) > time();
    }

    /**
     * @return array<string,mixed>
     */
    private function leaseSummary(array $record): array
    {
        return [
            'lease_id' => (string) ($record['lease_id'] ?? ''),
            'lease_key' => (string) ($record['lease_key'] ?? ''),
            'owner' => (string) ($record['owner'] ?? ''),
            'repo_root_hash' => (string) data_get($record, 'repo.repo_root_hash', ''),
            'base_ref' => (string) data_get($record, 'repo.base_ref', ''),
            'expires_at' => (string) data_get($record, 'lease.expires_at', ''),
        ];
    }

    private function leaseKey(string $repoRootHash, string $baseRef): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256([$repoRootHash, $baseRef]);
    }

    /**
     * @return array<string,mixed>
     */
    private function record(string $areaId, array $payload): array
    {
        $path = $this->recordPath($areaId);
        $recordPayload = ['schema_version' => self::RECORD_SCHEMA, 'recorded_at' => AreaFocusUtcClock::atomNow()] + $payload;
        AreaFocusAppendOnlyJsonlRecorder::append($path, $recordPayload);

        return $recordPayload + ['lease_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-775',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => $this->claimPolicy(false),
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ] + $extra;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(bool $mergeQueueMayRun): array
    {
        return [
            'provider_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'merge_performed' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'merge_queue_may_run_under_lease' => $mergeQueueMayRun,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['recorded_at'], $copy['lease_storage_status'], $copy['lease_hash']);

        return $copy;
    }
}
