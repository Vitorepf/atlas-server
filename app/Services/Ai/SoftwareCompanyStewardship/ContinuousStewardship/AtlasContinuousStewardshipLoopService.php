<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-745 · Atlas Continuous Stewardship Loop scheduler-safe tick.
 *
 * This service promotes AP-744 to a controlled always-on motor boundary. It is
 * deliberately admission-only: disabled by default, one cycle per tick, lock
 * lease, rate limit, append-only local state when requested, and no provider,
 * Dev/Forge dispatch, branch creation or target repository mutation.
 */
final class AtlasContinuousStewardshipLoopService
{
    public const STATE_SCHEMA = 'atlas.continuous_stewardship.loop_state.v1';

    public const TICK_SCHEMA = 'atlas.continuous_stewardship.loop_tick.v1';

    public const RECORD_SCHEMA = 'atlas.continuous_stewardship.loop_record.v1';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_READY_TO_TICK = 'ready_to_tick';

    public const STATUS_TICK_COMPLETED = 'tick_completed';

    public const STATUS_TICK_RECORDED = 'tick_recorded';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AreaStewardshipActiveOperatingService $activeOperation,
    ) {}

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
            ? storage_path('atlas/software_company_stewardship/continuous_stewardship_loop')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/continuous_stewardship_loop';
    }

    public function cycleFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    public function lockFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.lock.json';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $lastTick = $this->lastTick($areaId);
        $lock = $this->readLock($areaId);
        $lockActive = $this->lockActive($lock);

        return $this->finalize([
            'schema_version' => self::STATE_SCHEMA,
            'status' => $this->projectedStatus($policy, $lastTick, $lockActive),
            'ap_contract' => 'AP-745',
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Loop',
            'source_ap_contracts' => ['AP-711', 'AP-744'],
            'mode' => 'scheduler_safe_projection',
            'policy' => $policy,
            'last_tick' => $this->tickSummary($lastTick),
            'lock' => $this->lockSummary($lock),
            'next_allowed_at' => $this->nextAllowedAt($lastTick, (int) $policy['min_interval_seconds']),
            'blockers' => $this->projectedBlockers($policy, $lastTick, $lockActive),
            'next_actions' => $this->projectedNextActions($policy, $lastTick, $lockActive),
            'claim_policy' => $this->claimPolicy(false, false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function tick(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $record = (bool) ($input['record_continuous_cycle'] ?? false);
        $force = (bool) ($input['force'] ?? $input['force_continuous_tick'] ?? false);

        if (! (bool) $policy['enabled'] || (bool) $policy['kill_switch_active']) {
            return $this->finalize([
                'schema_version' => self::TICK_SCHEMA,
                'status' => self::STATUS_PAUSED,
                'ap_contract' => 'AP-745',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Atlas Continuous Stewardship Loop',
                'source_ap_contracts' => ['AP-711', 'AP-744'],
                'mode' => 'scheduler_safe_tick',
                'record_continuous_cycle_requested' => $record,
                'policy' => $policy,
                'blockers' => $this->pauseBlockers($policy),
                'next_actions' => ['Enable the continuous loop from Product Mode controls before a scheduler tick can operate.'],
                'claim_policy' => $this->claimPolicy($record, false),
            ]);
        }

        $lock = $this->readLock($areaId);
        if ($this->lockActive($lock)) {
            return $this->finalize([
                'schema_version' => self::TICK_SCHEMA,
                'status' => self::STATUS_LOCKED,
                'ap_contract' => 'AP-745',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Atlas Continuous Stewardship Loop',
                'source_ap_contracts' => ['AP-711', 'AP-744'],
                'mode' => 'scheduler_safe_tick',
                'record_continuous_cycle_requested' => $record,
                'policy' => $policy,
                'lock' => $this->lockSummary($lock),
                'blockers' => ['continuous_loop_lock_active'],
                'next_actions' => ['Wait for the active AP-745 lease to expire or inspect the scheduler lock before retrying.'],
                'claim_policy' => $this->claimPolicy($record, false),
            ]);
        }

        $lastTick = $this->lastTick($areaId);
        if (! $force && $this->rateLimited($lastTick, (int) $policy['min_interval_seconds'])) {
            return $this->finalize([
                'schema_version' => self::TICK_SCHEMA,
                'status' => self::STATUS_RATE_LIMITED,
                'ap_contract' => 'AP-745',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Atlas Continuous Stewardship Loop',
                'source_ap_contracts' => ['AP-711', 'AP-744'],
                'mode' => 'scheduler_safe_tick',
                'record_continuous_cycle_requested' => $record,
                'policy' => $policy,
                'last_tick' => $this->tickSummary($lastTick),
                'next_allowed_at' => $this->nextAllowedAt($lastTick, (int) $policy['min_interval_seconds']),
                'blockers' => ['continuous_loop_min_interval_not_elapsed'],
                'next_actions' => ['Respect AP-745 rate limit or retry with an explicit force flag during manual verification.'],
                'claim_policy' => $this->claimPolicy($record, false),
            ]);
        }

        $lease = $this->acquireLock($areaId, (int) $policy['lock_ttl_seconds']);
        try {
            $operation = is_array($input['active_operation_report'] ?? null)
                ? $input['active_operation_report']
                : $this->activeOperation->operate($input + [
                    'area_id' => $areaId,
                    'record_active_operation' => (bool) ($input['record_active_operation'] ?? false),
                ]);

            $operationStatus = (string) ($operation['status'] ?? 'unknown');
            $tickStatus = in_array($operationStatus, [
                AreaStewardshipActiveOperatingService::STATUS_READY,
                AreaStewardshipActiveOperatingService::STATUS_PARTIAL,
                AreaStewardshipActiveOperatingService::STATUS_AWAITING_HANDOFF,
            ], true)
                ? ($record ? self::STATUS_TICK_RECORDED : self::STATUS_TICK_COMPLETED)
                : self::STATUS_BLOCKED;

            $payload = [
                'schema_version' => self::TICK_SCHEMA,
                'status' => $tickStatus,
                'ap_contract' => 'AP-745',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Atlas Continuous Stewardship Loop',
                'source_ap_contracts' => ['AP-711', 'AP-744'],
                'mode' => 'scheduler_safe_tick',
                'record_continuous_cycle_requested' => $record,
                'policy' => $policy,
                'lease' => $this->lockSummary($lease),
                'active_operation_status' => $operationStatus,
                'active_operation_id' => (string) ($operation['operation_id'] ?? ''),
                'active_operation_hash' => (string) ($operation['operation_hash'] ?? ''),
                'active_operation' => $operation,
                'blockers' => $tickStatus === self::STATUS_BLOCKED
                    ? $this->operationBlockers($operation)
                    : [],
                'next_actions' => $this->tickNextActions($tickStatus, $operation),
                'claim_policy' => $this->claimPolicy($record, true),
            ];

            return $this->finalize($this->maybeRecord($areaId, $payload, $record));
        } finally {
            $this->releaseLock($areaId);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function policy(array $input): array
    {
        $enabled = (bool) ($input['enabled']
            ?? $input['continuous_loop_enabled']
            ?? config('atlas.software_company_stewardship.continuous_loop.enabled', false));
        $killSwitch = (bool) ($input['kill_switch']
            ?? $input['kill_switch_active']
            ?? config('atlas.software_company_stewardship.continuous_loop.kill_switch', false));

        return [
            'enabled' => $enabled,
            'kill_switch_active' => $killSwitch,
            'min_interval_seconds' => max(0, (int) ($input['min_interval_seconds']
                ?? config('atlas.software_company_stewardship.continuous_loop.min_interval_seconds', 900))),
            'lock_ttl_seconds' => max(30, (int) ($input['lock_ttl_seconds']
                ?? config('atlas.software_company_stewardship.continuous_loop.lock_ttl_seconds', 600))),
            'max_cycles_per_tick' => 1,
            'default_enabled' => false,
            'scheduler_installed_by_ap745' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $lastTick
     */
    private function projectedStatus(array $policy, ?array $lastTick, bool $lockActive): string
    {
        if (! (bool) $policy['enabled'] || (bool) $policy['kill_switch_active']) {
            return self::STATUS_PAUSED;
        }
        if ($lockActive) {
            return self::STATUS_LOCKED;
        }
        if ($this->rateLimited($lastTick, (int) $policy['min_interval_seconds'])) {
            return self::STATUS_RATE_LIMITED;
        }

        return self::STATUS_READY_TO_TICK;
    }

    /**
     * @param  array<string,mixed>|null  $lastTick
     * @return list<string>
     */
    private function projectedBlockers(array $policy, ?array $lastTick, bool $lockActive): array
    {
        if (! (bool) $policy['enabled']) {
            return ['continuous_loop_disabled_by_default'];
        }
        if ((bool) $policy['kill_switch_active']) {
            return ['continuous_loop_kill_switch_active'];
        }
        if ($lockActive) {
            return ['continuous_loop_lock_active'];
        }
        if ($this->rateLimited($lastTick, (int) $policy['min_interval_seconds'])) {
            return ['continuous_loop_min_interval_not_elapsed'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>|null  $lastTick
     * @return list<string>
     */
    private function projectedNextActions(array $policy, ?array $lastTick, bool $lockActive): array
    {
        if (! (bool) $policy['enabled']) {
            return ['Enable AP-745 from Product Mode controls only when the operator wants a scheduler-safe tick.'];
        }
        if ((bool) $policy['kill_switch_active']) {
            return ['Clear Product Mode kill switch before any continuous stewardship tick.'];
        }
        if ($lockActive) {
            return ['Wait for the existing AP-745 lock lease to expire before scheduling another tick.'];
        }
        if ($this->rateLimited($lastTick, (int) $policy['min_interval_seconds'])) {
            return ['Wait until next_allowed_at before scheduling another AP-745 tick.'];
        }

        return ['One scheduler-safe AP-744 tick is admitted; recording remains explicit and append-only.'];
    }

    /**
     * @return list<string>
     */
    private function pauseBlockers(array $policy): array
    {
        $blockers = [];
        if (! (bool) $policy['enabled']) {
            $blockers[] = 'continuous_loop_disabled_by_default';
        }
        if ((bool) $policy['kill_switch_active']) {
            $blockers[] = 'continuous_loop_kill_switch_active';
        }

        return $blockers !== [] ? $blockers : ['continuous_loop_paused'];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return list<string>
     */
    private function operationBlockers(array $operation): array
    {
        $blockers = array_values(array_filter((array) ($operation['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['ap744_active_operation_blocked'];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return list<string>
     */
    private function tickNextActions(string $status, array $operation): array
    {
        if ($status === self::STATUS_BLOCKED) {
            return ['Repair AP-744 blockers before scheduling the next continuous stewardship tick.'];
        }

        $actions = ['Review AP-745 tick result in Product Mode before releasing any AP-726 Dev/Forge handoff.'];
        foreach (array_values(array_filter((array) ($operation['next_actions'] ?? []), 'is_string')) as $action) {
            $actions[] = $action;
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $records, bool $activeOperationCalled): array
    {
        return [
            'scheduler_safe' => true,
            'disabled_by_default' => true,
            'kill_switch_enforced' => true,
            'lock_lease_enforced' => true,
            'rate_limit_enforced' => true,
            'max_cycles_per_tick' => '1',
            'records_cycle_when_requested' => $records,
            'active_operation_called_when_admitted' => $activeOperationCalled,
            'writes_local_state' => $records || $activeOperationCalled,
            'persistence' => 'jsonl_append_only_when_requested',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'installs_scheduler' => false,
            'creates_executor' => false,
            'creates_runtime' => false,
            'creates_new_os' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'destructive_change' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['loop_storage_status' => 'projected'];
        }

        $payload = $payload + ['loop_storage_status' => 'recorded'];
        $tickId = $this->tickId($payload);
        $existing = $this->findTick($this->cycleFilePath($areaId), $tickId);
        if ($existing !== null) {
            return array_merge($existing, ['loop_storage_status' => 'existing']);
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'tick_id' => $tickId,
            'recorded_at' => $this->now(),
        ];

        $this->appendJsonl($this->cycleFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastTick(string $areaId): ?array
    {
        $path = $this->cycleFilePath($areaId);
        if (! is_file($path)) {
            return null;
        }

        $last = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $last = $decoded;
            }
        }

        return $last;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findTick(string $path, string $tickId): ?array
    {
        if (! is_file($path) || $tickId === '') {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['tick_id'] ?? '') === $tickId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function acquireLock(string $areaId, int $ttlSeconds): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $payload = [
            'schema_version' => 'atlas.continuous_stewardship.loop_lock.v1',
            'area_id' => $areaId,
            'lock_id' => 'csl_lock_'.substr(MissionCanonicalHash::sha256($areaId.'|'.$now->format(DateTimeInterface::ATOM)), 0, 20),
            'acquired_at' => $now->format(DateTimeInterface::ATOM),
            'expires_at' => $now->modify('+'.$ttlSeconds.' seconds')->format(DateTimeInterface::ATOM),
        ];

        File::ensureDirectoryExists($this->storageDir());
        file_put_contents($this->lockFilePath($areaId), json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    private function releaseLock(string $areaId): void
    {
        File::delete($this->lockFilePath($areaId));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readLock(string $areaId): ?array
    {
        $path = $this->lockFilePath($areaId);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>|null  $lock
     */
    private function lockActive(?array $lock): bool
    {
        if ($lock === null) {
            return false;
        }

        $expiresAt = $this->time((string) ($lock['expires_at'] ?? ''));

        return $expiresAt !== null && $expiresAt > time();
    }

    /**
     * @param  array<string,mixed>|null  $lastTick
     */
    private function rateLimited(?array $lastTick, int $intervalSeconds): bool
    {
        if ($intervalSeconds <= 0 || $lastTick === null) {
            return false;
        }

        $recordedAt = $this->time((string) ($lastTick['recorded_at'] ?? $lastTick['generated_at'] ?? ''));

        return $recordedAt !== null && (time() - $recordedAt) < $intervalSeconds;
    }

    /**
     * @param  array<string,mixed>|null  $lastTick
     */
    private function nextAllowedAt(?array $lastTick, int $intervalSeconds): ?string
    {
        $recordedAt = $lastTick !== null
            ? $this->time((string) ($lastTick['recorded_at'] ?? $lastTick['generated_at'] ?? ''))
            : null;
        if ($recordedAt === null || $intervalSeconds <= 0) {
            return null;
        }

        return (new DateTimeImmutable('@'.($recordedAt + $intervalSeconds)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>|null  $tick
     * @return array<string,mixed>|null
     */
    private function tickSummary(?array $tick): ?array
    {
        if ($tick === null) {
            return null;
        }

        return [
            'tick_id' => (string) ($tick['tick_id'] ?? ''),
            'status' => (string) ($tick['status'] ?? 'unknown'),
            'area_id' => (string) ($tick['area_id'] ?? ''),
            'active_operation_status' => (string) ($tick['active_operation_status'] ?? ''),
            'active_operation_hash' => (string) ($tick['active_operation_hash'] ?? ''),
            'recorded_at' => (string) ($tick['recorded_at'] ?? ''),
            'tick_hash' => (string) ($tick['tick_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $lock
     * @return array<string,mixed>|null
     */
    private function lockSummary(?array $lock): ?array
    {
        if ($lock === null) {
            return null;
        }

        return [
            'lock_id' => (string) ($lock['lock_id'] ?? ''),
            'area_id' => (string) ($lock['area_id'] ?? ''),
            'acquired_at' => (string) ($lock['acquired_at'] ?? ''),
            'expires_at' => (string) ($lock['expires_at'] ?? ''),
            'active' => $this->lockActive($lock),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['tick_id'] = (string) ($payload['tick_id'] ?? $this->tickId($payload));
        $payload['tick_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function tickId(array $payload): string
    {
        return 'csl_'.substr(MissionCanonicalHash::sha256($this->stable($payload)), 0, 22);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset(
            $payload['tick_hash'],
            $payload['generated_at'],
            $payload['recorded_at'],
            $payload['loop_storage_status'],
            $payload['lease'],
            $payload['lock']
        );

        return $this->withoutVolatileTimestamps($payload);
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function withoutVolatileTimestamps(array $value): array
    {
        foreach (['generated_at', 'recorded_at', 'acquired_at', 'expires_at', 'decided_at'] as $key) {
            unset($value[$key]);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->withoutVolatileTimestamps($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function time(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }
}
