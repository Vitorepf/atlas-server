<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\JsonFileStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-766 · Atlas Continuous Stewardship Runner (control plane).
 *
 * This is the operator-facing 24h runner control plane for the Atlas Software
 * Company Stewardship Stack. It does NOT reimplement the scheduler-safe tick:
 * it composes AP-746 (which itself composes AP-745 -> AP-744) and adds the
 * control-plane primitives that AP-745/AP-746/AP-764 do not own:
 *
 *   - per-area kill switch (on top of the AP-746 global kill switch);
 *   - a daily run budget per area;
 *   - an idempotent runner-scoped lock per area;
 *   - explicit dry-run vs execute modes;
 *   - a component-bridge probe that reports Finding Engine, Branch Materializer,
 *     Dev/Forge Bridge and Evidence Bridge as invoked / deferred_by_governance /
 *     deferred_component_missing;
 *   - a single unified operator run receipt.
 *
 * It performs no provider call, no branch/worktree creation, no Dev/Forge
 * dispatch, no merge/deploy and no secret access. In execute mode, when every
 * gate passes, it delegates exactly one AP-746 tick (read-only over the repo)
 * and surfaces the resulting refs. Everything else is deferred and never hidden.
 */
final class ContinuousStewardshipRunnerService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.continuous_runner.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.continuous_runner_record.v1';

    public const STATUS_SCHEMA = 'atlas.software_company_stewardship.continuous_runner_status.v1';

    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_EXECUTE = 'execute';

    // Runner statuses.
    public const STATUS_PROJECTED = 'projected';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_NOT_DUE = 'not_due';

    public const STATUS_RAN = 'ran';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_MAX_RUNS_PER_DAY = 48;

    public const DEFAULT_LOCK_TTL_SECONDS = 600;

    public const DEFAULT_MIN_INTERVAL_SECONDS = 900;

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AtlasContinuousStewardshipRecurringSchedulerService $scheduler,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->scheduler->setStorageRootForTesting($dir !== null ? $dir.'/ap746' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/continuous_stewardship_runner')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/continuous_stewardship_runner';
    }

    public function runFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    public function lockFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.runner.lock.json';
    }

    /**
     * Operator-facing status read-model: last receipt, budget usage today, gate
     * states and next steps. Never runs a tick and never writes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function status(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $gates = $this->evaluateGates($areaId, $policy, self::MODE_DRY_RUN);
        $projection = $this->schedulerProjection($areaId, $policy);
        $lastReceipt = $this->lastRun($areaId);

        return $this->finalize([
            'schema_version' => self::STATUS_SCHEMA,
            'status' => $gates['admitted'] ? 'ready' : $gates['status'],
            'ap_contract' => 'AP-766',
            'area_id' => $areaId,
            'runner_id' => $this->runnerId($input),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Runner',
            'source_ap_contracts' => ['AP-744', 'AP-745', 'AP-746', 'AP-764'],
            'policy' => $policy,
            'kill_switch_status' => $gates['kill_switch_status'],
            'pause_status' => $gates['pause_status'],
            'budget_status' => $gates['budget_status'],
            'lock_status' => $gates['lock_status'],
            'budget' => $gates['budget'],
            'next_allowed_at' => $projection['next_allowed_at'] ?? null,
            'last_run' => $this->runSummary($lastReceipt),
            'invoked_components' => $this->probeComponents(self::MODE_DRY_RUN, false, false),
            'blockers' => $gates['blockers'],
            'next_actions' => $this->statusNextActions($gates, $projection),
            'claim_policy' => $this->claimPolicy(false, false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $mode = $this->mode($input);

        return $mode === self::MODE_EXECUTE
            ? $this->runExecute($input)
            : $this->runDryRun($input);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runDryRun(array $input): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $record = (bool) ($input['record_runner_run'] ?? false);
        $gates = $this->evaluateGates($areaId, $policy, self::MODE_DRY_RUN);
        $projection = $this->schedulerProjection($areaId, $policy);

        $payload = $this->baseReceipt($areaId, $input, self::MODE_DRY_RUN);
        $payload = array_merge($payload, [
            'status' => $gates['admitted'] ? self::STATUS_PROJECTED : $gates['status'],
            'tick_attempted' => false,
            'tick_status' => 'not_attempted',
            'tick_admitted' => false,
            'kill_switch_status' => $gates['kill_switch_status'],
            'pause_status' => $gates['pause_status'],
            'budget_status' => $gates['budget_status'],
            'lock_status' => $gates['lock_status'],
            'budget' => $gates['budget'],
            'next_allowed_at' => $projection['next_allowed_at'] ?? null,
            'scheduler_projection_status' => (string) ($projection['status'] ?? 'unknown'),
            'invoked_components' => $this->probeComponents(self::MODE_DRY_RUN, false, false),
            'evidence_refs' => [],
            'inbox_refs' => [],
            'errors' => [],
            'would_admit_tick' => $gates['admitted'] && (string) ($projection['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED,
            'blockers' => $gates['admitted'] ? [] : $gates['blockers'],
            'next_actions' => $this->dryRunNextActions($gates, $projection),
            'claim_policy' => $this->claimPolicy(false, $record),
        ]);

        return $this->finalize($this->maybeRecord($areaId, $payload, $record));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runExecute(array $input): array
    {
        $areaId = $this->areaId($input);
        $policy = $this->policy($input);
        $record = (bool) ($input['record_runner_run'] ?? true);
        $gates = $this->evaluateGates($areaId, $policy, self::MODE_EXECUTE);

        $payload = $this->baseReceipt($areaId, $input, self::MODE_EXECUTE);
        $payload = array_merge($payload, [
            'kill_switch_status' => $gates['kill_switch_status'],
            'pause_status' => $gates['pause_status'],
            'budget_status' => $gates['budget_status'],
            'lock_status' => $gates['lock_status'],
            'budget' => $gates['budget'],
            'evidence_refs' => [],
            'inbox_refs' => [],
            'errors' => [],
        ]);

        // A blocked gate must short-circuit before any tick is attempted.
        if (! $gates['admitted']) {
            $payload = array_merge($payload, [
                'status' => $gates['status'],
                'tick_attempted' => false,
                'tick_status' => 'not_attempted',
                'tick_admitted' => false,
                'next_allowed_at' => $this->schedulerProjection($areaId, $policy)['next_allowed_at'] ?? null,
                'invoked_components' => $this->probeComponents(self::MODE_EXECUTE, false, false),
                'blockers' => $gates['blockers'],
                'next_actions' => $this->blockedNextActions($gates),
                'claim_policy' => $this->claimPolicy(false, $record),
            ]);

            return $this->finalize($this->maybeRecord($areaId, $payload, $record));
        }

        // Acquire the runner-scoped lock, delegate exactly one AP-746 tick,
        // then release the lock no matter what.
        $lease = $this->acquireLock($areaId, (int) $policy['lock_ttl_seconds']);
        $injectedOperation = is_array($input['active_operation_report'] ?? null);

        try {
            $schedulerRun = $this->scheduler->run($this->schedulerInput($input, $areaId, $policy));
            $tickAdmitted = $this->tickAdmitted($schedulerRun);

            $payload = array_merge($payload, [
                'status' => $this->statusFromSchedulerRun($schedulerRun, $tickAdmitted),
                'tick_attempted' => true,
                'tick_admitted' => $tickAdmitted,
                'tick_status' => (string) ($schedulerRun['tick_status'] ?? data_get($schedulerRun, 'continuous_loop_tick.status', 'unknown')),
                'scheduler_run_id' => (string) ($schedulerRun['scheduler_run_id'] ?? ''),
                'scheduler_run_status' => (string) ($schedulerRun['status'] ?? 'unknown'),
                'next_allowed_at' => $schedulerRun['next_allowed_at'] ?? data_get($schedulerRun, 'continuous_loop.next_allowed_at'),
                'lock_status' => 'acquired_released',
                'lock' => $this->lockSummary($lease),
                'invoked_components' => $this->probeComponents(self::MODE_EXECUTE, $tickAdmitted, $injectedOperation),
                'evidence_refs' => $tickAdmitted ? $this->evidenceRefs($schedulerRun) : [],
                'inbox_refs' => $tickAdmitted ? $this->inboxRefs($schedulerRun) : [],
                'errors' => [],
                'blockers' => $tickAdmitted ? [] : $this->schedulerBlockers($schedulerRun),
                'next_actions' => $this->executeNextActions($tickAdmitted, $schedulerRun),
                'claim_policy' => $this->claimPolicy($tickAdmitted, $record),
            ]);
        } catch (Throwable $e) {
            $payload = array_merge($payload, [
                'status' => self::STATUS_BLOCKED,
                'tick_attempted' => true,
                'tick_admitted' => false,
                'tick_status' => 'error',
                'lock_status' => 'acquired_released',
                'invoked_components' => $this->probeComponents(self::MODE_EXECUTE, false, $injectedOperation),
                'errors' => ['continuous_runner_tick_exception: '.$e->getMessage()],
                'blockers' => ['continuous_runner_tick_exception'],
                'next_actions' => ['Inspect the AP-746/AP-745/AP-744 chain; the delegated tick raised an exception.'],
                'claim_policy' => $this->claimPolicy(false, $record),
            ]);
        } finally {
            $this->releaseLock($areaId);
        }

        return $this->finalize($this->maybeRecord($areaId, $payload, $record));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function baseReceipt(string $areaId, array $input, string $mode): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-766',
            'runner_id' => $this->runnerId($input),
            'area_id' => $areaId,
            'mode' => $mode,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Runner',
            'source_ap_contracts' => ['AP-744', 'AP-745', 'AP-746', 'AP-764'],
            'policy' => $this->policy($input),
            'scheduler_run_id' => '',
            'scheduler_run_status' => 'not_run',
        ];
    }

    /**
     * Evaluate the runner-owned control-plane gates in deterministic order:
     * global kill -> area kill -> pause -> budget -> lock.
     *
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function evaluateGates(string $areaId, array $policy, string $mode): array
    {
        $budget = $this->budgetState($areaId, $policy);
        $lockActive = $this->lockActive($this->readLock($areaId));
        $pauseActive = $this->pauseActive($policy);

        $killSwitchStatus = 'clear';
        $pauseStatus = $pauseActive ? 'paused_until' : 'clear';
        $budgetStatus = $budget['exhausted'] ? 'exhausted' : 'within_budget';
        $lockStatus = $lockActive ? 'held_by_other' : 'free';

        $admitted = true;
        $status = self::STATUS_PROJECTED;
        $blockers = [];

        if ((bool) $policy['global_kill_switch']) {
            $killSwitchStatus = 'global_active';
            $admitted = false;
            $status = self::STATUS_PAUSED;
            $blockers[] = 'continuous_runner_global_kill_switch_active';
        } elseif ((bool) $policy['area_kill_switch']) {
            $killSwitchStatus = 'area_active';
            $admitted = false;
            $status = self::STATUS_PAUSED;
            $blockers[] = 'continuous_runner_area_kill_switch_active';
        } elseif (! (bool) $policy['enabled']) {
            $admitted = false;
            $status = self::STATUS_PAUSED;
            $blockers[] = 'continuous_runner_disabled_by_default';
        } elseif ($pauseActive) {
            $admitted = false;
            $status = self::STATUS_PAUSED;
            $blockers[] = 'continuous_runner_pause_until_active';
        } elseif ($budget['exhausted']) {
            $admitted = false;
            $status = self::STATUS_BUDGET_EXHAUSTED;
            $blockers[] = 'continuous_runner_daily_budget_exhausted';
        } elseif ($lockActive) {
            $admitted = false;
            $status = self::STATUS_LOCKED;
            $blockers[] = 'continuous_runner_lock_held_by_other';
        }

        return [
            'admitted' => $admitted,
            'status' => $status,
            'kill_switch_status' => $killSwitchStatus,
            'pause_status' => $pauseStatus,
            'budget_status' => $budgetStatus,
            'lock_status' => $lockStatus,
            'budget' => $budget,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function policy(array $input): array
    {
        $enabled = (bool) ($input['enabled']
            ?? $input['runner_enabled']
            ?? config('atlas.software_company_stewardship.continuous_runner.enabled', false));
        $globalKill = (bool) ($input['global_kill_switch']
            ?? $input['kill_switch']
            ?? config('atlas.software_company_stewardship.continuous_runner.kill_switch', false));
        $areaKill = (bool) ($input['area_kill_switch']
            ?? config('atlas.software_company_stewardship.continuous_runner.area_kill_switch', false));

        return [
            'enabled' => $enabled,
            'global_kill_switch' => $globalKill,
            'area_kill_switch' => $areaKill,
            'pause_until' => $this->pauseUntil($input),
            'min_interval_seconds' => max(0, (int) ($input['min_interval_seconds']
                ?? config('atlas.software_company_stewardship.continuous_runner.min_interval_seconds', self::DEFAULT_MIN_INTERVAL_SECONDS))),
            'lock_ttl_seconds' => max(30, (int) ($input['lock_ttl_seconds']
                ?? config('atlas.software_company_stewardship.continuous_runner.lock_ttl_seconds', self::DEFAULT_LOCK_TTL_SECONDS))),
            'max_runs_per_day' => max(0, (int) ($input['max_runs_per_day']
                ?? config('atlas.software_company_stewardship.continuous_runner.max_runs_per_day', self::DEFAULT_MAX_RUNS_PER_DAY))),
            'max_ticks_per_run' => 1,
            'default_enabled' => false,
            'installs_scheduler' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * Daily run budget per area, counted from admitted execute receipts recorded
     * today (UTC). Idempotent dry-runs and blocked attempts never consume budget.
     *
     * @param  array<string,mixed>  $policy
     * @return array{day:string,max_runs_per_day:int,used_today:int,remaining:int,exhausted:bool}
     */
    private function budgetState(string $areaId, array $policy): array
    {
        $day = $this->today();
        $max = (int) $policy['max_runs_per_day'];
        $used = 0;

        foreach ($this->records($areaId) as $record) {
            if ((string) ($record['mode'] ?? '') !== self::MODE_EXECUTE) {
                continue;
            }
            if (! (bool) ($record['tick_admitted'] ?? false)) {
                continue;
            }
            if ($this->dayOf((string) ($record['recorded_at'] ?? $record['generated_at'] ?? '')) === $day) {
                $used++;
            }
        }

        $remaining = max(0, $max - $used);

        return [
            'day' => $day,
            'max_runs_per_day' => $max,
            'used_today' => $used,
            'remaining' => $remaining,
            'exhausted' => $used >= $max,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function schedulerInput(array $input, string $areaId, array $policy): array
    {
        $out = [
            'area_id' => $areaId,
            'enabled' => true,
            'scheduler_enabled' => true,
            'continuous_loop_enabled' => true,
            'kill_switch' => false,
            'pause_until' => (string) ($policy['pause_until'] ?? ''),
            'min_interval_seconds' => (int) $policy['min_interval_seconds'],
            'lock_ttl_seconds' => (int) $policy['lock_ttl_seconds'],
            'record_scheduler_run' => (bool) ($input['record_scheduler_run'] ?? true),
            'record_continuous_cycle' => (bool) ($input['record_continuous_cycle'] ?? true),
            'force_scheduler_run' => (bool) ($input['force_scheduler_run'] ?? false),
        ];

        if (is_array($input['active_operation_report'] ?? null)) {
            $out['active_operation_report'] = $input['active_operation_report'];
        }
        if (is_array($input['operator_receipts'] ?? null)) {
            $out['operator_receipts'] = $input['operator_receipts'];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function schedulerProjection(string $areaId, array $policy): array
    {
        return $this->scheduler->project([
            'area_id' => $areaId,
            'enabled' => (bool) $policy['enabled'] && ! (bool) $policy['global_kill_switch'] && ! (bool) $policy['area_kill_switch'],
            'continuous_loop_enabled' => (bool) $policy['enabled'],
            'kill_switch' => (bool) $policy['global_kill_switch'],
            'pause_until' => (string) ($policy['pause_until'] ?? ''),
            'min_interval_seconds' => (int) $policy['min_interval_seconds'],
            'lock_ttl_seconds' => (int) $policy['lock_ttl_seconds'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     */
    private function tickAdmitted(array $schedulerRun): bool
    {
        return in_array((string) ($schedulerRun['tick_status'] ?? ''), [
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED,
        ], true);
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     */
    private function statusFromSchedulerRun(array $schedulerRun, bool $tickAdmitted): string
    {
        if ($tickAdmitted) {
            return self::STATUS_RAN;
        }

        return match ((string) ($schedulerRun['status'] ?? '')) {
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RATE_LIMITED => self::STATUS_NOT_DUE,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED => self::STATUS_LOCKED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED => self::STATUS_PAUSED,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * The four component bridges this runner is prepared to drive. Each is probed
     * by class presence (deferred_component_missing when absent) and governance
     * (deferred_by_governance when present but out of scope for this slice).
     *
     * @return list<array{key:string,class:string,present:bool,runner_may_invoke:bool,invoked:bool,status:string,reason:string}>
     */
    private function probeComponents(string $mode, bool $tickAdmitted, bool $injectedOperation): array
    {
        $components = [];
        foreach ($this->componentCatalog() as $spec) {
            $present = class_exists($spec['class']);
            $invoked = false;

            if (! $present) {
                $status = 'deferred_component_missing';
                $reason = 'Bridge class is not present yet; runner is prepared to call it when it ships.';
            } elseif (! $spec['runner_may_invoke']) {
                $status = 'deferred_by_governance';
                $reason = $spec['governance_reason'];
            } elseif ($mode === self::MODE_DRY_RUN) {
                $status = 'deferred_dry_run';
                $reason = 'Dry-run mode does not invoke any bridge.';
            } elseif (! $tickAdmitted) {
                $status = 'not_reached';
                $reason = 'Tick was not admitted, so the bridge was not reached this invocation.';
            } elseif ($spec['invoked_via_tick'] && $injectedOperation) {
                $status = 'simulated_via_injected_operation';
                $reason = 'Tick completed with an injected AP-744 operation report; bridge was not actually executed.';
            } elseif ($spec['invoked_via_tick']) {
                $status = 'invoked_via_tick';
                $invoked = true;
                $reason = 'Invoked indirectly by the admitted AP-746 -> AP-745 -> AP-744 tick.';
            } else {
                $status = 'available_not_invoked';
                $reason = 'Bridge is present but not part of this slice of the tick.';
            }

            $components[] = [
                'key' => $spec['key'],
                'class' => $spec['class'],
                'present' => $present,
                'runner_may_invoke' => $spec['runner_may_invoke'],
                'invoked' => $invoked,
                'status' => $status,
                'reason' => $reason,
            ];
        }

        return $components;
    }

    /**
     * @return list<array{key:string,class:string,runner_may_invoke:bool,invoked_via_tick:bool,governance_reason:string}>
     */
    private function componentCatalog(): array
    {
        return [
            [
                'key' => 'finding_engine',
                'class' => 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AgenticEngineeringOsFindingEngineService',
                'runner_may_invoke' => true,
                'invoked_via_tick' => true,
                'governance_reason' => '',
            ],
            [
                'key' => 'branch_materializer',
                'class' => 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AreaFocusBranchSandboxMaterializerService',
                'runner_may_invoke' => false,
                'invoked_via_tick' => false,
                'governance_reason' => 'Runner must not create branches/worktrees; AP-756 materialization stays operator-gated.',
            ],
            [
                'key' => 'dev_forge_bridge',
                'class' => 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AreaFocusDevForgeReleaseService',
                'runner_may_invoke' => false,
                'invoked_via_tick' => false,
                'governance_reason' => 'Runner must not consume Dev/Forge; AP-747/AP-749/AP-758/AP-759 stay operator-gated.',
            ],
            [
                'key' => 'evidence_bridge',
                'class' => 'App\\Services\\Ai\\SoftwareCompanyStewardship\\StewardshipEvolution\\StewardshipOutcomeEvidenceBridgeService',
                'runner_may_invoke' => false,
                'invoked_via_tick' => false,
                'governance_reason' => 'Evidence Ledger / Morning Inbox emission (AP-740) is a separate operator-reviewed step.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @return list<array<string,string>>
     */
    private function evidenceRefs(array $schedulerRun): array
    {
        $tick = is_array($schedulerRun['continuous_loop_tick'] ?? null) ? $schedulerRun['continuous_loop_tick'] : [];
        $operation = is_array($tick['active_operation'] ?? null) ? $tick['active_operation'] : [];

        $candidates = [
            ['kind' => 'scheduler_run', 'id' => (string) ($schedulerRun['scheduler_run_id'] ?? ''), 'hash' => (string) ($schedulerRun['run_hash'] ?? '')],
            ['kind' => 'continuous_tick', 'id' => (string) ($tick['tick_id'] ?? ''), 'hash' => (string) ($tick['tick_hash'] ?? '')],
            ['kind' => 'active_operation', 'id' => (string) ($operation['operation_id'] ?? $tick['active_operation_id'] ?? ''), 'hash' => (string) ($operation['operation_hash'] ?? $tick['active_operation_hash'] ?? '')],
            ['kind' => 'operational_cycle', 'id' => (string) ($operation['operational_cycle_id'] ?? ''), 'hash' => (string) ($operation['operational_cycle_hash'] ?? '')],
        ];

        return array_values(array_filter($candidates, static fn (array $ref): bool => $ref['id'] !== '' || $ref['hash'] !== ''));
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @return list<array<string,mixed>>
     */
    private function inboxRefs(array $schedulerRun): array
    {
        $tick = is_array($schedulerRun['continuous_loop_tick'] ?? null) ? $schedulerRun['continuous_loop_tick'] : [];
        $operation = is_array($tick['active_operation'] ?? null) ? $tick['active_operation'] : [];
        $queue = is_array($operation['operation_queue'] ?? null) ? $operation['operation_queue'] : [];

        $decisionCount = (int) ($queue['operator_decision_count'] ?? 0);
        if ($decisionCount <= 0) {
            return [];
        }

        // Morning Inbox items are emitted by AP-740 in a separate operator step;
        // here we only surface the operator-decision queue produced by the cycle.
        return [[
            'kind' => 'operator_decision_queue',
            'operator_decision_count' => $decisionCount,
            'ready_dev_forge_handoff_count' => (int) ($queue['ready_dev_forge_handoff_count'] ?? 0),
            'note' => 'Morning Inbox emission stays an AP-740 operator-reviewed step; runner does not push inbox items.',
        ]];
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @return list<string>
     */
    private function schedulerBlockers(array $schedulerRun): array
    {
        $blockers = array_values(array_filter((array) ($schedulerRun['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['continuous_runner_tick_not_completed'];
    }

    /**
     * @param  array<string,mixed>  $gates
     * @param  array<string,mixed>  $projection
     * @return list<string>
     */
    private function statusNextActions(array $gates, array $projection): array
    {
        if (! $gates['admitted']) {
            return $this->blockedNextActions($gates);
        }

        if ((string) ($projection['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED) {
            return ['Runner is admitted and a tick is due; run with --mode=execute to delegate one AP-746 tick.'];
        }

        return ['Runner gates are clear; wait until next_allowed_at before the next due AP-746 tick.'];
    }

    /**
     * @param  array<string,mixed>  $gates
     * @param  array<string,mixed>  $projection
     * @return list<string>
     */
    private function dryRunNextActions(array $gates, array $projection): array
    {
        if (! $gates['admitted']) {
            return $this->blockedNextActions($gates);
        }

        $actions = ['Dry-run only: no tick, no provider, no branch. Re-run with --mode=execute when ready.'];
        if ((string) ($projection['status'] ?? '') !== AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED) {
            $actions[] = 'AP-746 projection is not currently scheduled; the execute tick may return not_due until next_allowed_at.';
        }

        return $actions;
    }

    /**
     * @param  array<string,mixed>  $gates
     * @return list<string>
     */
    private function blockedNextActions(array $gates): array
    {
        return match ($gates['status']) {
            self::STATUS_PAUSED => match ($gates['kill_switch_status']) {
                'global_active' => ['Clear the global continuous runner kill switch before any invocation.'],
                'area_active' => ['Clear the per-area continuous runner kill switch before this area can run.'],
                default => $gates['pause_status'] === 'paused_until'
                    ? ['Wait until pause_until expires or clear the runner pause policy by operator decision.']
                    : ['Enable the continuous runner from Product Mode controls or config before it can run.'],
            },
            self::STATUS_BUDGET_EXHAUSTED => ['Daily run budget for this area is exhausted; wait for the next UTC day or raise --max-runs-per-day by operator decision.'],
            self::STATUS_LOCKED => ['Another runner invocation holds the area lock; wait for the lease to expire before retrying.'],
            default => ['Resolve runner gate blockers before the next invocation.'],
        };
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @return list<string>
     */
    private function executeNextActions(bool $tickAdmitted, array $schedulerRun): array
    {
        if (! $tickAdmitted) {
            return ['Tick was not admitted by AP-746; respect rate limit / lock and retry after next_allowed_at.'];
        }

        $actions = ['Review the recorded runner run and AP-744 cycle output in Product Mode / Morning Inbox.'];
        foreach (array_values(array_filter((array) ($schedulerRun['next_actions'] ?? []), 'is_string')) as $action) {
            $actions[] = $action;
        }
        $actions[] = 'Release any accepted handoff via AP-747 -> AP-756 -> AP-749 -> AP-758 -> AP-759 -> AP-750; runner never creates branches or calls providers.';

        return StewardshipStringListNormalizer::uniqueStrings($actions);
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $tickInvoked, bool $recordsRun): array
    {
        return [
            'control_plane_only' => true,
            'composes_ap746' => true,
            'reimplements_tick' => false,
            'per_area_kill_switch_enforced' => true,
            'global_kill_switch_enforced' => true,
            'daily_budget_enforced' => true,
            'runner_lock_enforced' => true,
            'pause_policy_enforced' => true,
            'rate_limit_reused_from_ap745' => true,
            'max_ticks_per_run' => '1',
            'disabled_by_default' => true,
            'installs_scheduler' => false,
            'records_run_when_requested' => $recordsRun,
            'ap746_tick_called_when_admitted' => $tickInvoked,
            'persistence' => 'jsonl_append_only',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'uses_codex_app_automation' => false,
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
            return $payload + ['runner_storage_status' => 'projected'];
        }

        $payload = $payload + ['runner_storage_status' => 'recorded'];
        $runId = $this->runId($payload);
        $existing = $this->findRun($this->runFilePath($areaId), $runId);
        if ($existing !== null) {
            return array_merge($existing, ['runner_storage_status' => 'existing']);
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'runner_run_id' => $runId,
            'recorded_at' => $this->now(),
        ];

        AppendOnlyJsonlStore::append($this->runFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function records(string $areaId): array
    {
        return AppendOnlyJsonlStore::read($this->runFilePath($areaId));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastRun(string $areaId): ?array
    {
        $records = $this->records($areaId);

        return $records === [] ? null : $records[array_key_last($records)];
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
            if ((string) ($run['runner_run_id'] ?? '') === $runId) {
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
            'runner_run_id' => (string) ($run['runner_run_id'] ?? ''),
            'mode' => (string) ($run['mode'] ?? ''),
            'status' => (string) ($run['status'] ?? 'unknown'),
            'tick_status' => (string) ($run['tick_status'] ?? ''),
            'tick_admitted' => (bool) ($run['tick_admitted'] ?? false),
            'recorded_at' => (string) ($run['recorded_at'] ?? ''),
            'run_hash' => (string) ($run['run_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function acquireLock(string $areaId, int $ttlSeconds): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $payload = [
            'schema_version' => 'atlas.software_company_stewardship.continuous_runner_lock.v1',
            'area_id' => $areaId,
            'lock_id' => 'csr_lock_'.substr(MissionCanonicalHash::sha256($areaId.'|'.$now->format(DateTimeInterface::ATOM)), 0, 20),
            'acquired_at' => $now->format(DateTimeInterface::ATOM),
            'expires_at' => $now->modify('+'.$ttlSeconds.' seconds')->format(DateTimeInterface::ATOM),
        ];

        JsonFileStore::write($this->lockFilePath($areaId), $payload);

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
        return JsonFileStore::readArray($this->lockFilePath($areaId));
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
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function runId(array $payload): string
    {
        return 'csr_'.substr(MissionCanonicalHash::sha256($this->stable($payload)), 0, 22);
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
            $payload['runner_storage_status'],
            $payload['last_run'],
            $payload['lock'],
            $payload['budget'],
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
            'next_allowed_at',
            'runner_storage_status',
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

    private function mode(array $input): string
    {
        $value = strtolower(trim((string) ($input['mode'] ?? self::MODE_DRY_RUN)));

        return $value === self::MODE_EXECUTE ? self::MODE_EXECUTE : self::MODE_DRY_RUN;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : self::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function runnerId(array $input): string
    {
        $value = trim((string) ($input['runner_id'] ?? 'atlas_continuous_stewardship_runner'));

        return $this->slug($value);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function pauseUntil(array $input): ?string
    {
        $value = trim((string) ($input['pause_until'] ?? ''));
        $timestamp = $this->time($value);
        if ($value === '' || $timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable('@'.$timestamp))
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function dayOf(string $value): ?string
    {
        $timestamp = $this->time($value);
        if ($timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
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
