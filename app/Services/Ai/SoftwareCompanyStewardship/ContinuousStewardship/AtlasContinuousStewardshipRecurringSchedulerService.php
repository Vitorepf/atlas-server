<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-746 · recurring scheduler-safe runner for Atlas Continuous Stewardship.
 *
 * This is the contract a cron/heartbeat/launchd caller can invoke repeatedly.
 * It never installs that scheduler and never gains execution authority. It
 * admits at most one AP-745 tick per invocation, with pause policy, kill switch,
 * bounded cadence and optional append-only scheduler evidence.
 */
final class AtlasContinuousStewardshipRecurringSchedulerService
{
    use ContinuousStewardshipClock;

    public const SCHEDULE_SCHEMA = 'atlas.continuous_stewardship.recurring_scheduler.v1';

    public const RUN_SCHEMA = 'atlas.continuous_stewardship.recurring_scheduler_run.v1';

    public const RECORD_SCHEMA = 'atlas.continuous_stewardship.recurring_scheduler_record.v1';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_NOT_DUE = 'not_due';

    public const STATUS_RUN_COMPLETED = 'run_completed';

    public const STATUS_RUN_RECORDED = 'run_recorded';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AtlasContinuousStewardshipLoopService $continuousLoop,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->continuousLoop->setStorageRootForTesting($dir !== null ? $dir.'/ap745' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/continuous_stewardship_scheduler')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/continuous_stewardship_scheduler';
    }

    public function schedulerFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $pauseActive = $this->pauseActive($policy);
        $loopState = $this->loopProjection($areaId, $policy, $input);
        $lastRun = $this->lastRun($areaId);

        return $this->finalize([
            'schema_version' => self::SCHEDULE_SCHEMA,
            'status' => $this->projectedStatus($policy, $pauseActive, $loopState),
            'ap_contract' => 'AP-746',
            'area_id' => $areaId,
            'scheduler_id' => $this->schedulerId($input),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Loop',
            'source_ap_contracts' => ['AP-711', 'AP-745'],
            'mode' => 'recurring_scheduler_safe_projection',
            'policy' => $policy,
            'continuous_loop' => $this->loopSummary($loopState),
            'last_scheduler_run' => $this->runSummary($lastRun),
            'next_allowed_at' => $loopState['next_allowed_at'] ?? null,
            'blockers' => $this->projectedBlockers($policy, $pauseActive, $loopState),
            'next_actions' => $this->projectedNextActions($policy, $pauseActive, $loopState),
            'claim_policy' => $this->claimPolicy(false, false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $recordScheduler = (bool) ($input['record_scheduler_run'] ?? false);
        $recordContinuous = (bool) ($input['record_continuous_cycle'] ?? $recordScheduler);
        $force = (bool) ($input['force_scheduler_run'] ?? false);
        $pauseActive = $this->pauseActive($policy);
        $loopState = $this->loopProjection($areaId, $policy, $input);
        $projectionStatus = $this->projectedStatus($policy, $pauseActive, $loopState);

        if (! $this->admitted($projectionStatus, $force)) {
            return $this->finalize([
                'schema_version' => self::RUN_SCHEMA,
                'status' => $projectionStatus,
                'ap_contract' => 'AP-746',
                'area_id' => $areaId,
                'scheduler_id' => $this->schedulerId($input),
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Atlas Continuous Stewardship Loop',
                'source_ap_contracts' => ['AP-711', 'AP-745'],
                'mode' => 'recurring_scheduler_safe_run',
                'record_scheduler_run_requested' => $recordScheduler,
                'record_continuous_cycle_requested' => $recordContinuous,
                'policy' => $policy,
                'continuous_loop' => $this->loopSummary($loopState),
                'blockers' => $this->projectedBlockers($policy, $pauseActive, $loopState),
                'next_actions' => $this->projectedNextActions($policy, $pauseActive, $loopState),
                'claim_policy' => $this->claimPolicy($recordScheduler, false),
            ]);
        }

        $tick = $this->continuousLoop->tick(array_merge($input, [
            'area_id' => $areaId,
            'enabled' => (bool) $policy['continuous_loop_enabled'],
            'kill_switch' => (bool) $policy['kill_switch_active'],
            'min_interval_seconds' => (int) $policy['min_interval_seconds'],
            'lock_ttl_seconds' => (int) $policy['lock_ttl_seconds'],
            'record_continuous_cycle' => $recordContinuous,
            'force_continuous_tick' => $force,
        ]));

        $status = $this->statusFromTick($tick, $recordScheduler);
        $payload = [
            'schema_version' => self::RUN_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-746',
            'area_id' => $areaId,
            'scheduler_id' => $this->schedulerId($input),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Loop',
            'source_ap_contracts' => ['AP-711', 'AP-745'],
            'mode' => 'recurring_scheduler_safe_run',
            'record_scheduler_run_requested' => $recordScheduler,
            'record_continuous_cycle_requested' => $recordContinuous,
            'policy' => $policy,
            'tick_status' => (string) ($tick['status'] ?? 'unknown'),
            'tick_id' => (string) ($tick['tick_id'] ?? ''),
            'tick_hash' => (string) ($tick['tick_hash'] ?? ''),
            'continuous_loop_tick' => $tick,
            'blockers' => $this->tickBlockers($tick),
            'next_actions' => $this->runNextActions($status, $tick),
            'claim_policy' => $this->claimPolicy($recordScheduler, true),
        ];

        return $this->finalize($this->maybeRecord($areaId, $payload, $recordScheduler));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function policy(array $input): array
    {
        $enabled = (bool) ($input['enabled']
            ?? $input['scheduler_enabled']
            ?? $input['recurring_scheduler_enabled']
            ?? config('atlas.software_company_stewardship.continuous_scheduler.enabled', false));
        $killSwitch = (bool) ($input['kill_switch']
            ?? $input['kill_switch_active']
            ?? config('atlas.software_company_stewardship.continuous_scheduler.kill_switch', false));

        return [
            'scheduler_enabled' => $enabled,
            'continuous_loop_enabled' => (bool) ($input['continuous_loop_enabled'] ?? $enabled),
            'kill_switch_active' => $killSwitch,
            'pause_until' => $this->pauseUntil($input),
            'pause_reason' => trim((string) ($input['pause_reason'] ?? '')),
            'min_interval_seconds' => max(0, (int) ($input['min_interval_seconds']
                ?? config('atlas.software_company_stewardship.continuous_loop.min_interval_seconds', 900))),
            'lock_ttl_seconds' => max(30, (int) ($input['lock_ttl_seconds']
                ?? config('atlas.software_company_stewardship.continuous_loop.lock_ttl_seconds', 600))),
            'max_ticks_per_run' => 1,
            'default_enabled' => false,
            'installs_scheduler' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function loopProjection(string $areaId, array $policy, array $input): array
    {
        if (is_array($input['continuous_loop_state'] ?? null)) {
            return $input['continuous_loop_state'];
        }

        return $this->continuousLoop->project(array_merge($input, [
            'area_id' => $areaId,
            'enabled' => (bool) $policy['continuous_loop_enabled'],
            'kill_switch' => (bool) $policy['kill_switch_active'],
            'min_interval_seconds' => (int) $policy['min_interval_seconds'],
            'lock_ttl_seconds' => (int) $policy['lock_ttl_seconds'],
        ]));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $loopState
     */
    private function projectedStatus(array $policy, bool $pauseActive, array $loopState): string
    {
        if (! (bool) $policy['scheduler_enabled'] || (bool) $policy['kill_switch_active'] || $pauseActive) {
            return self::STATUS_PAUSED;
        }

        return match ((string) ($loopState['status'] ?? 'unknown')) {
            AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK => self::STATUS_SCHEDULED,
            AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED => self::STATUS_NOT_DUE,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED => self::STATUS_LOCKED,
            AtlasContinuousStewardshipLoopService::STATUS_BLOCKED => self::STATUS_BLOCKED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED => self::STATUS_NOT_DUE,
            default => self::STATUS_PAUSED,
        };
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $loopState
     * @return list<string>
     */
    private function projectedBlockers(array $policy, bool $pauseActive, array $loopState): array
    {
        if (! (bool) $policy['scheduler_enabled']) {
            return ['continuous_scheduler_disabled_by_default'];
        }
        if ((bool) $policy['kill_switch_active']) {
            return ['continuous_scheduler_kill_switch_active'];
        }
        if ($pauseActive) {
            return ['continuous_scheduler_pause_until_active'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED) {
            return ['continuous_loop_next_tick_not_due'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_LOCKED) {
            return ['continuous_loop_lock_active'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_BLOCKED) {
            return array_values(array_filter((array) ($loopState['blockers'] ?? ['continuous_loop_blocked']), 'is_string'));
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $loopState
     * @return list<string>
     */
    private function projectedNextActions(array $policy, bool $pauseActive, array $loopState): array
    {
        if (! (bool) $policy['scheduler_enabled']) {
            return ['Enable AP-746 only from Product Mode controls or an explicit operator-owned scheduler config.'];
        }
        if ((bool) $policy['kill_switch_active']) {
            return ['Clear Product Mode kill switch before any recurring scheduler invocation can tick.'];
        }
        if ($pauseActive) {
            return ['Wait until pause_until expires or clear the AP-746 pause policy by operator decision.'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED) {
            return ['Wait until AP-745 next_allowed_at before the recurring scheduler invokes another tick.'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_LOCKED) {
            return ['Wait for the AP-745 lock lease before recurring scheduler retry.'];
        }
        if ((string) ($loopState['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK) {
            return ['One AP-745 tick is due; record scheduler evidence when running in unattended mode.'];
        }

        return ['Review AP-745 loop state before enabling recurring scheduler mode.'];
    }

    private function admitted(string $projectionStatus, bool $force): bool
    {
        if ($projectionStatus === self::STATUS_SCHEDULED) {
            return true;
        }

        return $force && $projectionStatus === self::STATUS_NOT_DUE;
    }

    /**
     * @param  array<string,mixed>  $tick
     */
    private function statusFromTick(array $tick, bool $recordScheduler): string
    {
        return match ((string) ($tick['status'] ?? 'unknown')) {
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED => self::STATUS_RUN_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED => $recordScheduler ? self::STATUS_RUN_RECORDED : self::STATUS_RUN_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED => self::STATUS_RATE_LIMITED,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED => self::STATUS_LOCKED,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * @param  array<string,mixed>  $tick
     * @return list<string>
     */
    private function tickBlockers(array $tick): array
    {
        $status = (string) ($tick['status'] ?? '');
        if (in_array($status, [
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED,
        ], true)) {
            return [];
        }

        $blockers = array_values(array_filter((array) ($tick['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['continuous_scheduler_tick_not_completed'];
    }

    /**
     * @param  array<string,mixed>  $tick
     * @return list<string>
     */
    private function runNextActions(string $status, array $tick): array
    {
        if (in_array($status, [self::STATUS_RUN_COMPLETED, self::STATUS_RUN_RECORDED], true)) {
            $actions = ['Review AP-746 scheduler run in Product Mode before releasing any Dev/Forge handoff.'];
            foreach (array_values(array_filter((array) ($tick['next_actions'] ?? []), 'is_string')) as $action) {
                $actions[] = $action;
            }

            return StewardshipStringListNormalizer::uniqueStrings($actions);
        }

        return ['Resolve AP-746/AP-745 blockers before the next recurring scheduler invocation.'];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordsSchedulerRun, bool $tickAttempted): array
    {
        return [
            'recurring_scheduler_safe' => true,
            'external_scheduler_invocation_allowed' => true,
            'installs_scheduler' => false,
            'disabled_by_default' => true,
            'pause_policy_enforced' => true,
            'kill_switch_enforced' => true,
            'ap745_rate_limit_reused' => true,
            'ap745_lock_lease_reused' => true,
            'max_ticks_per_run' => '1',
            'records_scheduler_run_when_requested' => $recordsSchedulerRun,
            'ap745_tick_called_when_due' => $tickAttempted,
            'writes_local_state' => $recordsSchedulerRun || $tickAttempted,
            'persistence' => 'jsonl_append_only_when_requested',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'branch_created' => false,
            'worktree_created' => false,
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
            return $payload + ['scheduler_storage_status' => 'projected'];
        }

        $payload = $payload + ['scheduler_storage_status' => 'recorded'];
        $runId = $this->runId($payload);
        $existing = $this->findRun($this->schedulerFilePath($areaId), $runId);
        if ($existing !== null) {
            return array_merge($existing, ['scheduler_storage_status' => 'existing']);
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'scheduler_run_id' => $runId,
            'recorded_at' => $this->now(),
        ];

        AppendOnlyJsonlStore::append($this->schedulerFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastRun(string $areaId): ?array
    {
        $runs = AppendOnlyJsonlStore::read($this->schedulerFilePath($areaId));

        return $runs === [] ? null : $runs[array_key_last($runs)];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRun(string $path, string $runId): ?array
    {
        if ($runId === '') {
            return null;
        }

        foreach (AppendOnlyJsonlStore::read($path) as $run) {
            if ((string) ($run['scheduler_run_id'] ?? '') === $runId) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $run
     * @return array<string,mixed>|null
     */
    private function runSummary(?array $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'scheduler_run_id' => (string) ($run['scheduler_run_id'] ?? ''),
            'status' => (string) ($run['status'] ?? 'unknown'),
            'area_id' => (string) ($run['area_id'] ?? ''),
            'tick_status' => (string) ($run['tick_status'] ?? ''),
            'tick_hash' => (string) ($run['tick_hash'] ?? ''),
            'recorded_at' => (string) ($run['recorded_at'] ?? ''),
            'run_hash' => (string) ($run['run_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $loop
     * @return array<string,mixed>
     */
    private function loopSummary(array $loop): array
    {
        return [
            'schema_version' => (string) ($loop['schema_version'] ?? ''),
            'status' => (string) ($loop['status'] ?? 'unknown'),
            'tick_id' => (string) ($loop['tick_id'] ?? ''),
            'tick_hash' => (string) ($loop['tick_hash'] ?? ''),
            'next_allowed_at' => $loop['next_allowed_at'] ?? null,
            'blockers' => array_values(array_filter((array) ($loop['blockers'] ?? []), 'is_string')),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['scheduler_run_id'] = (string) ($payload['scheduler_run_id'] ?? $this->runId($payload));
        $payload['run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function runId(array $payload): string
    {
        return 'csls_'.substr(MissionCanonicalHash::sha256($this->stable($payload)), 0, 22);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset(
            $payload['run_hash'],
            $payload['generated_at'],
            $payload['recorded_at'],
            $payload['scheduler_storage_status'],
            $payload['last_scheduler_run']
        );

        return $this->withoutVolatileTimestamps($payload);
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function withoutVolatileTimestamps(array $value): array
    {
        foreach ([
            'generated_at',
            'recorded_at',
            'acquired_at',
            'expires_at',
            'decided_at',
            'scheduler_storage_status',
            'loop_storage_status',
        ] as $key) {
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

        return $value !== '' ? $this->slug($value) : 'agentic_engineering_os';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function schedulerId(array $input): string
    {
        $value = trim((string) ($input['scheduler_id'] ?? 'atlas_continuous_stewardship_loop'));

        return $this->slug($value);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function pauseUntil(array $input): ?string
    {
        $value = trim((string) ($input['pause_until'] ?? ''));
        if ($value === '' || $this->time($value) === null) {
            return null;
        }

        return (new DateTimeImmutable('@'.$this->time($value)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function pauseActive(array $policy): bool
    {
        $pauseUntil = $this->time((string) ($policy['pause_until'] ?? ''));

        return $pauseUntil !== null && $pauseUntil > time();
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }
}
