<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-778 · real, auditable first-start wrapper for the 24h stewardship loop.
 *
 * This service composes AP-777 readiness and AP-766 runner. It does not install
 * a scheduler, create branches/worktrees, invoke providers, dispatch Dev/Forge,
 * merge, deploy or touch secrets.
 */
final class ContinuousStewardshipDayStartService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.continuous_24h_start.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.continuous_24h_start_record.v1';

    public const STATUS_FIRST_TICK_EXECUTED = 'first_tick_executed';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly ContinuousStewardshipDayReadinessService $readiness,
        private readonly ContinuousStewardshipRunnerService $runner,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->readiness->setStorageRootForTesting($dir !== null ? $dir.'/readiness' : null);
        $this->runner->setStorageRootForTesting($dir !== null ? $dir.'/runner' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/continuous_stewardship_start')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/continuous_stewardship_start';
    }

    public function receiptFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function start(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? ContinuousStewardshipRunnerService::DEFAULT_AREA_ID));
        $focus = (string) ($input['focus'] ?? 'dev_forge');
        $record = (bool) ($input['record'] ?? false);
        $maxRunsPerDay = max(0, (int) ($input['max_runs_per_day'] ?? ContinuousStewardshipRunnerService::DEFAULT_MAX_RUNS_PER_DAY));
        $minIntervalSeconds = max(0, (int) ($input['min_interval_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_MIN_INTERVAL_SECONDS));
        $lockTtlSeconds = max(30, (int) ($input['lock_ttl_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_LOCK_TTL_SECONDS));

        $readinessReport = $this->readiness->assess([
            'area_id' => $areaId,
            'focus' => $focus,
            'repo_root' => (string) ($input['repo_root'] ?? ''),
            'duration_hours' => (int) ($input['duration_hours'] ?? 24),
            'enabled' => (bool) ($input['enabled'] ?? false),
            'global_kill_switch' => (bool) ($input['global_kill_switch'] ?? $input['kill_switch'] ?? false),
            'area_kill_switch' => (bool) ($input['area_kill_switch'] ?? false),
            'pause_until' => (string) ($input['pause_until'] ?? ''),
            'max_runs_per_day' => $maxRunsPerDay,
            'min_interval_seconds' => $minIntervalSeconds,
            'lock_ttl_seconds' => $lockTtlSeconds,
        ]);

        $payload = $this->basePayload($areaId, $focus, $input, $readinessReport);
        $readinessBlockers = array_values(array_filter((array) ($readinessReport['blockers'] ?? []), 'is_string'));

        if (($readinessReport['status'] ?? '') !== ContinuousStewardshipDayReadinessService::STATUS_READY) {
            return $this->finalize($this->maybeRecord($areaId, array_merge($payload, [
                'final_status' => self::STATUS_BLOCKED,
                'runner_receipt' => null,
                'tick_admitted' => false,
                'tick_status' => 'not_attempted',
                'next_allowed_at' => data_get($readinessReport, 'runner_status.next_allowed_at'),
                'blockers' => $readinessBlockers,
                'next_operator_action' => 'Resolve AP-777 readiness blockers before starting the 24h stewardship loop.',
                'operator_next_command' => $this->operatorNextCommand($areaId, $focus, $maxRunsPerDay, $minIntervalSeconds, $lockTtlSeconds),
                'claim_policy' => $this->claimPolicy(false, false, $record),
            ]), $record));
        }

        if (! (bool) ($input['execute_first_tick'] ?? false)) {
            return $this->finalize($this->maybeRecord($areaId, array_merge($payload, [
                'final_status' => self::STATUS_BLOCKED,
                'runner_receipt' => null,
                'tick_admitted' => false,
                'tick_status' => 'not_attempted',
                'next_allowed_at' => data_get($readinessReport, 'runner_status.next_allowed_at'),
                'blockers' => ['execute_first_tick_not_requested'],
                'next_operator_action' => 'Re-run with --execute-first-tick to prove one real AP-766 tick before scheduling recurrence.',
                'operator_next_command' => $this->operatorNextCommand($areaId, $focus, $maxRunsPerDay, $minIntervalSeconds, $lockTtlSeconds),
                'claim_policy' => $this->claimPolicy(false, false, $record),
            ]), $record));
        }

        $runnerReceipt = $this->runner->run($this->runnerInput($areaId, $input, $maxRunsPerDay, $minIntervalSeconds, $lockTtlSeconds));
        $tickAdmitted = (bool) ($runnerReceipt['tick_admitted'] ?? false);
        $runnerBlockers = array_values(array_filter((array) ($runnerReceipt['blockers'] ?? []), 'is_string'));
        $runnerStatusAfterStart = $this->runner->status([
            'area_id' => $areaId,
            'enabled' => true,
            'max_runs_per_day' => $maxRunsPerDay,
            'min_interval_seconds' => $minIntervalSeconds,
            'lock_ttl_seconds' => $lockTtlSeconds,
        ]);

        return $this->finalize($this->maybeRecord($areaId, array_merge($payload, [
            'final_status' => $tickAdmitted ? self::STATUS_FIRST_TICK_EXECUTED : self::STATUS_BLOCKED,
            'runner_receipt' => $runnerReceipt,
            'runner_status_after_start' => $runnerStatusAfterStart,
            'tick_admitted' => $tickAdmitted,
            'tick_status' => (string) ($runnerReceipt['tick_status'] ?? 'unknown'),
            'next_allowed_at' => $runnerStatusAfterStart['next_allowed_at'] ?? $runnerReceipt['next_allowed_at'] ?? data_get($readinessReport, 'runner_status.next_allowed_at'),
            'blockers' => $tickAdmitted ? [] : ($runnerBlockers !== [] ? $runnerBlockers : ['continuous_runner_first_tick_not_admitted']),
            'next_operator_action' => $tickAdmitted
                ? 'Schedule the recurrence externally using operator_next_command; do not install launchd/cron from this command.'
                : 'Do not schedule recurrence yet; resolve runner blockers or retry after next_allowed_at.',
            'operator_next_command' => $this->operatorNextCommand($areaId, $focus, $maxRunsPerDay, $minIntervalSeconds, $lockTtlSeconds),
            'claim_policy' => $this->claimPolicy(true, $tickAdmitted, $record),
        ]), $record));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public function list(array $input = []): array
    {
        return $this->records($this->slug((string) ($input['area_id'] ?? ContinuousStewardshipRunnerService::DEFAULT_AREA_ID)));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    public function replay(string $receiptId, array $input = []): ?array
    {
        foreach ($this->list($input) as $record) {
            if ((string) ($record['start_receipt_id'] ?? '') === $receiptId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $readinessReport
     * @return array<string,mixed>
     */
    private function basePayload(string $areaId, string $focus, array $input, array $readinessReport): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-778',
            'area_id' => $areaId,
            'focus' => $focus,
            'duration_hours' => (int) ($input['duration_hours'] ?? 24),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Loop',
            'source_ap_contracts' => ['AP-766', 'AP-777', 'AP-778'],
            'readiness_report' => $readinessReport,
            'scheduler_installed' => false,
            'branch_or_worktree_created' => false,
            'provider_or_dev_forge_invoked' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runnerInput(string $areaId, array $input, int $maxRunsPerDay, int $minIntervalSeconds, int $lockTtlSeconds): array
    {
        $out = [
            'area_id' => $areaId,
            'mode' => ContinuousStewardshipRunnerService::MODE_EXECUTE,
            'enabled' => true,
            'global_kill_switch' => false,
            'area_kill_switch' => false,
            'pause_until' => (string) ($input['pause_until'] ?? ''),
            'max_runs_per_day' => $maxRunsPerDay,
            'min_interval_seconds' => $minIntervalSeconds,
            'lock_ttl_seconds' => $lockTtlSeconds,
            'record_runner_run' => true,
            'record_scheduler_run' => true,
            'record_continuous_cycle' => true,
            'operator_receipts' => is_array($input['operator_receipts'] ?? null) ? $input['operator_receipts'] : [],
        ];

        if (is_array($input['active_operation_report'] ?? null)) {
            $out['active_operation_report'] = $input['active_operation_report'];
        }

        return $out;
    }

    private function operatorNextCommand(string $areaId, string $focus, int $maxRunsPerDay, int $minIntervalSeconds, int $lockTtlSeconds): string
    {
        return 'php artisan atlas:software-company-stewardship continuous-runner'
            .' --area='.$areaId
            .' --focus='.$focus
            .' --mode=execute'
            .' --enable-continuous-runner'
            .' --record-runner-run'
            .' --max-runs-per-day='.$maxRunsPerDay
            .' --min-interval-seconds='.$minIntervalSeconds
            .' --runner-lock-ttl-seconds='.$lockTtlSeconds
            .' --json';
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $runnerCalled, bool $tickAdmitted, bool $record): array
    {
        return [
            'composes_ap777_readiness' => true,
            'blocks_unless_readiness_ready' => true,
            'calls_ap766_runner_once_when_execute_first_tick' => $runnerCalled,
            'first_tick_admitted' => $tickAdmitted,
            'records_start_receipt_when_requested' => $record,
            'persistence' => 'jsonl_append_only',
            'operator_next_command_only' => true,
            'scheduler_installed' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'touches_secrets' => false,
            'mutates_target_repo' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        $payload = $payload + [
            'receipt_path' => $this->receiptFilePath($areaId),
            'generated_at' => $this->now(),
        ];
        $payload['start_receipt_id'] = $this->receiptId($payload);

        if (! $record) {
            return $payload + ['start_storage_status' => 'projected'];
        }

        $existing = $this->findReceipt($areaId, (string) $payload['start_receipt_id']);
        if ($existing !== null) {
            return array_merge($existing, ['start_storage_status' => 'existing']);
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'start_storage_status' => 'recorded',
            'recorded_at' => $this->now(),
        ];
        $this->appendJsonl($this->receiptFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['start_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function records(string $areaId): array
    {
        $path = $this->receiptFilePath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findReceipt(string $areaId, string $receiptId): ?array
    {
        foreach ($this->records($areaId) as $record) {
            if ((string) ($record['start_receipt_id'] ?? '') === $receiptId) {
                return $record;
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
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function receiptId(array $payload): string
    {
        return 'cs_start_'.substr(MissionCanonicalHash::sha256($this->identity($payload)), 0, 24);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset(
            $payload['generated_at'],
            $payload['recorded_at'],
            $payload['start_receipt_id'],
            $payload['start_storage_status'],
            $payload['start_hash'],
        );

        return $payload;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: 'default';

        return trim($slug, '_') ?: 'default';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
