<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-810 Health Pulse — called every N cycles to snapshot resource health.
 *
 * Checks memory headroom, git object count (auto-GC when loose objects > 1000),
 * JSONL ledger file size (auto-rotate when > 50 MB), zombie-process kill, and
 * free disk space. Returns a deterministic snapshot array; never throws.
 *
 * Contract:
 *   - read_only: false (may call `git gc`, rename ledger, kill zombie PIDs)
 *   - runs_provider: false
 *   - runs_loop: false
 *   - runs_merge: false
 *   - called_every_n_cycles: 10
 */
final class LoopHealthPulseService
{
    public const PULSE_SCHEMA = 'atlas.software_company_stewardship.loop_health_pulse.v1';

    /** Pulse every N cycles. */
    public const PULSE_EVERY_N_CYCLES = 10;

    /** Rotate JSONL when file exceeds this size in MB. */
    public const JSONL_ROTATE_MB = 50;

    /** Trigger git GC when loose object count exceeds this. */
    public const GIT_GC_LOOSE_OBJECT_THRESHOLD = 1000;

    /** Memory warning threshold: 80 % of PHP memory_limit. */
    public const MEMORY_WARN_FRACTION = 0.80;

    /** @var null|callable(string):string */
    private $commandRunner = null;

    /** @var null|callable():list<array{pid:int,cmd:string,cwd:string}> */
    private $processTableProvider = null;

    /** @var null|callable(int,string):bool */
    private $processKiller = null;

    /** @var null|callable():int */
    private $memoryProvider = null;

    /** @var null|callable(string):int */
    private $fileSizeProvider = null;

    /** @var null|callable(string):float */
    private $diskFreeProvider = null;

    // ---------------------------------------------------------------- test seams

    /** @param callable(string):string $runner */
    public function setCommandRunnerForTesting(callable $runner): void
    {
        $this->commandRunner = $runner;
    }

    /** @param callable():list<array{pid:int,cmd:string,cwd:string}> $provider */
    public function setProcessTableForTesting(callable $provider): void
    {
        $this->processTableProvider = $provider;
    }

    /** @param callable(int,string):bool $killer */
    public function setProcessKillerForTesting(callable $killer): void
    {
        $this->processKiller = $killer;
    }

    /** @param callable():int $provider returns memory_get_usage(true) */
    public function setMemoryProviderForTesting(callable $provider): void
    {
        $this->memoryProvider = $provider;
    }

    /** @param callable(string):int $provider returns file size in bytes */
    public function setFileSizeProviderForTesting(callable $provider): void
    {
        $this->fileSizeProvider = $provider;
    }

    /** @param callable(string):float $provider returns free disk GB */
    public function setDiskFreeProviderForTesting(callable $provider): void
    {
        $this->diskFreeProvider = $provider;
    }

    // ---------------------------------------------------------------- main API

    /**
     * Take a health snapshot. Mutating side-effects (gc, rotate, kill) only fire
     * when their thresholds are crossed. Never throws.
     *
     * @param  string  $jsonlPath  absolute path to the main JSONL ledger file
     * @return array<string,mixed>
     */
    public function pulse(int $cycleIndex, string $repoRoot, string $jsonlPath = ''): array
    {
        try {
            return $this->doPulse($cycleIndex, $repoRoot, $jsonlPath);
        } catch (Throwable $e) {
            return [
                'schema_version' => self::PULSE_SCHEMA,
                'cycle_index' => $cycleIndex,
                'healthy' => false,
                'error' => $e->getMessage(),
                'pulsed_at' => AreaFocusUtcClock::atomNow(),
            ];
        }
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return array<string,mixed>
     */
    private function doPulse(int $cycleIndex, string $repoRoot, string $jsonlPath): array
    {
        // 1. Memory
        $memoryBytes = $this->memoryProvider !== null
            ? (int) ($this->memoryProvider)()
            : memory_get_usage(true);
        $memoryMb = (int) round($memoryBytes / 1024 / 1024);
        $limitMb = $this->parseMemoryLimitMb();
        $memoryOk = $limitMb <= 0 || $memoryMb < (int) round($limitMb * self::MEMORY_WARN_FRACTION);

        // 2. Git objects
        $looseObjects = 0;
        $gitGcTriggered = false;
        if ($repoRoot !== '' && is_dir($repoRoot)) {
            $looseObjects = $this->gitLooseObjectCount($repoRoot);
            if ($looseObjects > self::GIT_GC_LOOSE_OBJECT_THRESHOLD) {
                $this->runCommand('git -C '.escapeshellarg($repoRoot).' gc --quiet --prune=now 2>/dev/null');
                $gitGcTriggered = true;
            }
        }

        // 3. JSONL rotation
        $jsonlSizeMb = 0.0;
        $jsonlRotated = false;
        if ($jsonlPath !== '' && is_file($jsonlPath)) {
            $bytes = $this->fileSizeProvider !== null
                ? (int) ($this->fileSizeProvider)($jsonlPath)
                : (int) filesize($jsonlPath);
            $jsonlSizeMb = round($bytes / 1024 / 1024, 2);
            if ($jsonlSizeMb > self::JSONL_ROTATE_MB) {
                $jsonlRotated = $this->rotateJsonl($jsonlPath, $cycleIndex);
            }
        }

        // 4. Zombie PID collection
        $zombiePidsKilled = $this->killZombieProcesses($repoRoot);

        // 5. Disk free
        $diskFreeGb = $this->diskFreeGb($repoRoot !== '' && is_dir($repoRoot) ? $repoRoot : sys_get_temp_dir());

        $healthy = $memoryOk && $diskFreeGb > 0.5;

        return [
            'schema_version' => self::PULSE_SCHEMA,
            'cycle_index' => $cycleIndex,
            'memory_mb' => $memoryMb,
            'memory_limit_mb' => $limitMb,
            'memory_ok' => $memoryOk,
            'git_object_count' => $looseObjects,
            'git_gc_triggered' => $gitGcTriggered,
            'jsonl_size_mb' => $jsonlSizeMb,
            'jsonl_rotated' => $jsonlRotated,
            'zombie_pids_killed' => $zombiePidsKilled,
            'disk_free_gb' => $diskFreeGb,
            'healthy' => $healthy,
            'pulsed_at' => AreaFocusUtcClock::atomNow(),
        ];
    }

    private function parseMemoryLimitMb(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '-1' || $raw === '') {
            return 0; // unlimited
        }
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) round($value / 1024),
            default => (int) round($value / 1024 / 1024),
        };
    }

    private function gitLooseObjectCount(string $repoRoot): int
    {
        try {
            $output = $this->runCommand('git -C '.escapeshellarg($repoRoot).' count-objects -v 2>/dev/null');
            if (preg_match('/^count:\s*(\d+)/m', $output, $m)) {
                return (int) $m[1];
            }
        } catch (Throwable) {
            // Non-fatal
        }

        return 0;
    }

    private function rotateJsonl(string $path, int $cycleIndex): bool
    {
        try {
            $rotated = $path.'.rotated_at_cycle_'.$cycleIndex.'_'.time();
            if (@rename($path, $rotated)) {
                // Create a fresh empty file so the runner can continue appending.
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, '');

                return true;
            }
        } catch (Throwable) {
            // Non-fatal
        }

        return false;
    }

    /**
     * Kill artisan/php processes not owned by the current PID that are running
     * inside the repo root worktree path — these are orphaned sandbox processes.
     *
     * @return list<int>
     */
    private function killZombieProcesses(string $repoRoot): array
    {
        $killed = [];
        $myPid = getmypid();

        try {
            $procs = $this->processTableProvider !== null
                ? ($this->processTableProvider)()
                : $this->readProcessTable();

            foreach ($procs as $proc) {
                $pid = (int) ($proc['pid'] ?? 0);
                if ($pid <= 0 || $pid === $myPid) {
                    continue;
                }
                $cmd = (string) ($proc['cmd'] ?? '');
                $cwd = (string) ($proc['cwd'] ?? '');
                $isArtisan = str_contains($cmd, 'artisan') || (str_contains($cmd, 'php') && str_contains($cmd, 'atlas'));
                $inRepoRoot = $repoRoot !== '' && str_starts_with($cwd, $repoRoot);
                if ($isArtisan && $inRepoRoot) {
                    $didKill = $this->processKiller !== null
                        ? (bool) ($this->processKiller)($pid, $cmd)
                        : $this->killPid($pid);
                    if ($didKill) {
                        $killed[] = $pid;
                    }
                }
            }
        } catch (Throwable) {
            // Non-fatal
        }

        return $killed;
    }

    /**
     * @return list<array{pid:int,cmd:string,cwd:string}>
     */
    private function readProcessTable(): array
    {
        try {
            $output = trim($this->runCommand('ps -eo pid,args 2>/dev/null || ps aux 2>/dev/null'));
            $procs = [];
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^\s*PID\b/i', $line)) {
                    continue;
                }
                if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m)) {
                    $pid = (int) $m[1];
                    $cmd = trim($m[2]);
                    // Attempt to read CWD from /proc (Linux) if available.
                    $cwd = '';
                    if (is_link('/proc/'.$pid.'/cwd')) {
                        $cwd = (string) @readlink('/proc/'.$pid.'/cwd');
                    }
                    $procs[] = ['pid' => $pid, 'cmd' => $cmd, 'cwd' => $cwd];
                }
            }

            return $procs;
        } catch (Throwable) {
            return [];
        }
    }

    private function killPid(int $pid): bool
    {
        try {
            return function_exists('posix_kill') && @posix_kill($pid, 15); // SIGTERM
        } catch (Throwable) {
            return false;
        }
    }

    private function diskFreeGb(string $path): float
    {
        try {
            if ($this->diskFreeProvider !== null) {
                return (float) ($this->diskFreeProvider)($path);
            }
            $bytes = @disk_free_space($path);

            return $bytes !== false ? round((float) $bytes / 1024 / 1024 / 1024, 2) : 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function runCommand(string $cmd): string
    {
        if ($this->commandRunner !== null) {
            return (string) ($this->commandRunner)($cmd);
        }
        $output = @shell_exec($cmd);

        return is_string($output) ? $output : '';
    }
}
