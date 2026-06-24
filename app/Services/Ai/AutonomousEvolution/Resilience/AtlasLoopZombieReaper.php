<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Resilience;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AtlasLoopZombieReaper
{
    public const SCHEMA_VERSION = 'atlas.loop.zombie_reaper.v1';

    /** @var list<string> */
    private const DEFAULT_TABLES = ['atlas_loop_targets', 'atlas_loop_tasks', 'atlas_loop_attempts'];

    /** @var list<string> */
    private const PID_COLUMNS = ['claimed_by_pid', 'worker_pid', 'pid', 'process_pid'];

    /** @var list<string> */
    private const CLAIM_TIME_COLUMNS = ['claimed_at', 'claim_started_at', 'heartbeat_at', 'updated_at', 'created_at'];

    /** @var list<string> */
    private const NULLABLE_CLAIM_COLUMNS = [
        'claimed_by_pid',
        'worker_pid',
        'pid',
        'process_pid',
        'claimed_by',
        'claimed_at',
        'lease_id',
        'lease_expires_at',
        'heartbeat_at',
    ];

    /**
     * @param  array<string,mixed>  $topologySnapshot
     * @param  array{tables?:list<string>,apply?:bool,dry_run?:bool,grace_seconds?:int,now?:string,release_status_by_table?:array<string,string>}  $options
     * @return array{schema_version:string,dry_run:bool,grace_seconds:int,reaped:list<array<string,mixed>>,spared:list<array<string,mixed>>}
     */
    public function reap(array $topologySnapshot, array $options = []): array
    {
        $dryRun = array_key_exists('apply', $options)
            ? ! (bool) $options['apply']
            : (bool) ($options['dry_run'] ?? true);
        $graceSeconds = max(0, (int) ($options['grace_seconds'] ?? 60));
        $now = Carbon::parse((string) ($options['now'] ?? Carbon::now('UTC')->toIso8601String()));
        $livePids = $this->livePids($topologySnapshot);
        $reaped = [];
        $spared = [];

        foreach ($this->tables($options) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $pidColumn = $this->firstExistingColumn($table, self::PID_COLUMNS);
            if ($pidColumn === null) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($pidColumn)->orderBy('id')->get() as $row) {
                $receipt = $this->receiptBase($table, $row, $pidColumn, $now);
                $pid = (int) ($row->{$pidColumn} ?? 0);

                if (isset($livePids[$pid])) {
                    $spared[] = $this->sortKeys($receipt + ['reason' => 'pid_alive']);
                    continue;
                }

                $ageSeconds = $this->claimAgeSeconds($table, $row, $now);
                $receipt['claim_age_seconds'] = $ageSeconds;
                if ($ageSeconds === null || $ageSeconds < $graceSeconds) {
                    $spared[] = $this->sortKeys($receipt + ['reason' => 'claim_age_below_grace']);
                    continue;
                }

                if (! $this->isClaimedStatus($table, $row)) {
                    $spared[] = $this->sortKeys($receipt + ['reason' => 'status_not_claimed']);
                    continue;
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($table, $row, $options): void {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update($this->releasePayload($table, $options));
                    });
                }

                $reaped[] = $this->sortKeys($receipt + [
                    'claim_age_seconds' => $ageSeconds,
                    'reason' => 'pid_absent_from_topology',
                    'released' => ! $dryRun,
                ]);
            }
        }

        return $this->sortKeys([
            'schema_version' => self::SCHEMA_VERSION,
            'dry_run' => $dryRun,
            'grace_seconds' => $graceSeconds,
            'reaped' => $this->sortReceipts($reaped),
            'spared' => $this->sortReceipts($spared),
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<string>
     */
    private function tables(array $options): array
    {
        $tables = $options['tables'] ?? self::DEFAULT_TABLES;

        return array_values(array_filter(array_map('strval', $tables), static fn (string $table): bool => $table !== ''));
    }

    /**
     * @param  array<string,mixed>  $topologySnapshot
     * @return array<int,true>
     */
    private function livePids(array $topologySnapshot): array
    {
        $pids = [];
        foreach (($topologySnapshot['processes'] ?? []) as $process) {
            if (is_array($process) && is_numeric($process['pid'] ?? null)) {
                $pids[(int) $process['pid']] = true;
            }
        }

        return $pids;
    }

    /**
     * @param  list<string>  $columns
     */
    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function claimAgeSeconds(string $table, object $row, Carbon $now): ?int
    {
        $column = $this->firstExistingColumn($table, self::CLAIM_TIME_COLUMNS);
        if ($column === null || empty($row->{$column})) {
            return null;
        }

        return (int) max(0, Carbon::parse((string) $row->{$column})->diffInSeconds($now));
    }

    private function isClaimedStatus(string $table, object $row): bool
    {
        if (! Schema::hasColumn($table, 'status')) {
            return true;
        }

        return in_array((string) ($row->status ?? ''), ['claimed', 'running'], true);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function releasePayload(string $table, array $options): array
    {
        $payload = [];
        foreach (self::NULLABLE_CLAIM_COLUMNS as $column) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = null;
            }
        }
        if (Schema::hasColumn($table, 'status')) {
            $payload['status'] = $this->releaseStatus($table, $options);
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = Carbon::now('UTC');
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function releaseStatus(string $table, array $options): string
    {
        $statuses = $options['release_status_by_table'] ?? [];
        if (is_array($statuses) && isset($statuses[$table])) {
            return (string) $statuses[$table];
        }

        return match ($table) {
            'atlas_loop_targets' => 'candidate',
            'atlas_loop_tasks' => 'pending',
            default => 'claimable',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptBase(string $table, object $row, string $pidColumn, Carbon $now): array
    {
        return [
            'checked_at' => $now->toIso8601String(),
            'claim_column' => $pidColumn,
            'pid' => (int) ($row->{$pidColumn} ?? 0),
            'row_id' => (string) ($row->id ?? ''),
            'status_before' => (string) ($row->status ?? ''),
            'table' => $table,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     * @return list<array<string,mixed>>
     */
    private function sortReceipts(array $receipts): array
    {
        usort($receipts, static function (array $a, array $b): int {
            return strcmp((string) ($a['table'] ?? ''), (string) ($b['table'] ?? ''))
                ?: strcmp((string) ($a['row_id'] ?? ''), (string) ($b['row_id'] ?? ''))
                ?: strcmp((string) ($a['reason'] ?? ''), (string) ($b['reason'] ?? ''));
        });

        return array_values($receipts);
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function sortKeys(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $this->sortKeys($item);
                }
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
