<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use Closure;
use Symfony\Component\Process\Process;

/**
 * Liveness supervisor for the file-based unified evolution loop.
 *
 * The unified loop persists report.json and heartbeat.json, but a killed PHP
 * worker can leave report.status=running behind. This service answers whether
 * that run is actually alive by combining persisted heartbeat/report freshness
 * with a real PHP process scan. It is read-only: restart is emitted as a command
 * to run under launchd/cron/operator control, never executed here.
 */
final class AtlasUnifiedLoopSupervisorService
{
    public const SCHEMA = 'atlas.loop.unified_supervisor.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_STALE_RUNNING = 'stale_running';

    public const STATUS_TERMINAL = 'terminal';

    public const STATUS_NO_RUN = 'no_run';

    public const STATUS_MISSING_REPORT = 'missing_report';

    private const DEFAULT_MAX_AGE_SECONDS = 900;

    /** @var (Closure():list<array{pid:int,command:string}>)|null */
    private ?Closure $processRows = null;

    /** @param  Closure():list<array{pid:int,command:string}>  $rows */
    public function setProcessRowsForTesting(Closure $rows): void
    {
        $this->processRows = $rows;
    }

    /**
     * @param  array{max_heartbeat_age_seconds?:int,max_report_age_seconds?:int,run_id?:?string}  $options
     * @return array<string,mixed>
     */
    public function assess(?string $runDir = null, array $options = []): array
    {
        $root = storage_path('atlas/loop/unified');
        $runDir = $runDir !== null && $runDir !== '' ? rtrim($runDir, '/') : $this->latestRunDir($root);
        if ($runDir === null) {
            return $this->payload(self::STATUS_NO_RUN, '', null, null, false, [], [
                'no_unified_loop_run_found',
            ], false, null);
        }

        $runId = (string) ($options['run_id'] ?? basename($runDir));
        $reportPath = $runDir.'/report.json';
        $heartbeatPath = $runDir.'/heartbeat.json';
        $report = $this->readJson($reportPath);
        if ($report === []) {
            return $this->payload(self::STATUS_MISSING_REPORT, $runId, null, null, false, [], [
                'report_json_missing_or_unreadable',
            ], false, null);
        }

        $maxHeartbeatAge = max(1, (int) ($options['max_heartbeat_age_seconds'] ?? self::DEFAULT_MAX_AGE_SECONDS));
        $maxReportAge = max(1, (int) ($options['max_report_age_seconds'] ?? self::DEFAULT_MAX_AGE_SECONDS));
        $now = time();
        $heartbeatAt = $this->heartbeatAt($heartbeatPath);
        $heartbeatAge = $heartbeatAt === null ? null : max(0, $now - $heartbeatAt);
        $reportAge = is_file($reportPath) ? max(0, $now - (int) filemtime($reportPath)) : null;
        $workers = $this->unifiedLoopWorkers($runId);
        $workerAlive = $workers !== [];
        $reportStatus = (string) ($report['status'] ?? '');
        $runningReport = $reportStatus === 'running';
        $stopFile = storage_path('atlas/loop/unified/STOP');

        $blockers = [];
        $warnings = [];
        if ($heartbeatAge === null) {
            $blockers[] = 'heartbeat_missing';
        } elseif ($heartbeatAge > $maxHeartbeatAge) {
            $blockers[] = 'heartbeat_stale';
        }
        if ($reportAge === null) {
            $blockers[] = 'report_missing';
        } elseif ($reportAge > $maxReportAge) {
            $blockers[] = 'report_stale';
        }
        if ($runningReport && ! $workerAlive) {
            $blockers[] = 'running_report_without_php_worker';
        }
        if (is_file($stopFile)) {
            $warnings[] = 'kill_switch_present';
        }

        $status = self::STATUS_HEALTHY;
        if (! $runningReport) {
            $status = self::STATUS_TERMINAL;
        } elseif ($blockers !== []) {
            $status = self::STATUS_STALE_RUNNING;
        }

        $restartRecommended = $status === self::STATUS_STALE_RUNNING && ! is_file($stopFile);
        $restartCommand = $restartRecommended ? $this->restartCommand($report, $runId) : null;

        return $this->payload(
            $status,
            $runId,
            $heartbeatAge,
            $reportAge,
            $workerAlive,
            $workers,
            $blockers,
            $restartRecommended,
            $restartCommand,
            [
                'report_status' => $reportStatus,
                'max_heartbeat_age_seconds' => $maxHeartbeatAge,
                'max_report_age_seconds' => $maxReportAge,
                'kill_switch_present' => is_file($stopFile),
                'warnings' => $warnings,
                'run_dir' => $runDir,
            ],
        );
    }

    /**
     * @return list<array{pid:int,command:string}>
     */
    private function unifiedLoopWorkers(string $runId): array
    {
        $workers = [];
        foreach ($this->processRows() as $row) {
            $command = trim((string) ($row['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            if (! $this->isPhpUnifiedLoopCommand($command)) {
                continue;
            }
            if (str_contains($command, 'atlas:loop:unified:report') || str_contains($command, 'atlas:loop:unified:supervisor')) {
                continue;
            }
            if (str_contains($command, '--run-id=') && ! str_contains($command, '--run-id='.$runId)) {
                continue;
            }
            $workers[] = [
                'pid' => (int) ($row['pid'] ?? 0),
                'command' => $command,
            ];
        }

        return $workers;
    }

    private function isPhpUnifiedLoopCommand(string $command): bool
    {
        $first = strtok($command, ' ');
        $first = is_string($first) ? basename($first) : '';
        if (preg_match('/^php(?:[0-9.]*)?$/', $first) !== 1) {
            return false;
        }

        return preg_match('/(?:^|\s)\S*artisan\s+atlas:loop:unified(?:\s|$)/', $command) === 1;
    }

    /**
     * @return list<array{pid:int,command:string}>
     */
    private function processRows(): array
    {
        if ($this->processRows !== null) {
            return ($this->processRows)();
        }

        $process = new Process(['ps', '-axo', 'pid=,command='], null, null, null, 20.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $rows[] = ['pid' => (int) $m[1], 'command' => $m[2]];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function restartCommand(array $report, string $runId): string
    {
        $modes = implode(',', array_values(array_filter(array_map('strval', (array) ($report['modes'] ?? ['deadcode', 'docs_structure'])))));
        $provider = trim((string) ($report['provider'] ?? ''));
        $parts = [
            'php',
            'artisan',
            'atlas:loop:unified',
            '--run-id='.$runId,
            '--max-seconds=86400',
            '--modes='.($modes !== '' ? $modes : 'deadcode,docs_structure'),
        ];
        if ($provider !== '') {
            $parts[] = '--provider='.$provider;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<array{pid:int,command:string}>  $workers
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        string $runId,
        ?int $heartbeatAge,
        ?int $reportAge,
        bool $workerAlive,
        array $workers,
        array $blockers,
        bool $restartRecommended,
        ?string $restartCommand,
        array $extra = [],
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'run_id' => $runId,
            'heartbeat_age_seconds' => $heartbeatAge,
            'report_age_seconds' => $reportAge,
            'php_worker_alive' => $workerAlive,
            'php_workers' => $workers,
            'restart_recommended' => $restartRecommended,
            'restart_command' => $restartCommand,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'mutates_code' => false,
                'kills_processes' => false,
                'restart_is_recommendation_only' => true,
            ],
        ] + $extra;
    }

    private function heartbeatAt(string $path): ?int
    {
        $heartbeat = $this->readJson($path);
        if (isset($heartbeat['at'])) {
            return (int) $heartbeat['at'];
        }
        if (is_file($path)) {
            return (int) filemtime($path);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function latestRunDir(string $root): ?string
    {
        if (! is_dir($root)) {
            return null;
        }
        $dirs = glob($root.'/run-*', GLOB_ONLYDIR) ?: [];
        if ($dirs === []) {
            return null;
        }
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $dirs[0];
    }
}
