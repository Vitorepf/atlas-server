<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Illuminate\Console\Command;
use App\Support\UtcIsoTimestamp;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Self-healing launchd probe (EVI-02). Runs via scheduler. Checks more than
 * "label present in launchctl list" — last exit code, new PHP Fatal blocks in
 * launchd.err.log, and (cheap defense) heartbeat freshness.
 *
 * Authority: docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §EVI-02
 * External authority remains EVI-01 (com.atlas.scheduler-watchdog).
 */
class AtlasSchedulerEnsureLaunchdCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:scheduler:ensure-launchd
        {--label=com.atlas.scheduler : launchd label to probe}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Verify launchd agent is healthy; reinstall if missing or failing (self-healing).';

    public function handle(?AtlasSchedulerHealthService $health = null): int
    {
        $label = (string) $this->option('label');
        $isMac = PHP_OS_FAMILY === 'Darwin';

        if (! $isMac) {
            return $this->emit([
                'action' => 'ensure-launchd',
                'label' => $label,
                'platform' => PHP_OS_FAMILY,
                'status' => 'skipped_non_darwin',
            ]);
        }

        $reasons = [];
        $agentPresent = $this->agentPresent($label);
        if (! $agentPresent) {
            $reasons[] = 'agent_absent';
        }

        $exitCode = $this->lastExitCode($label);
        if ($exitCode !== null && $exitCode !== 0) {
            $reasons[] = 'last_exit_code_nonzero';
        }

        $fatal = $this->newFatalBlocks();
        if ($fatal['new_blocks'] > 0) {
            $reasons[] = 'php_fatal_in_err_log';
        }

        // Cheap defense — NOT a proof case (ensure runs inside a live tick).
        $health ??= $this->laravel->make(AtlasSchedulerHealthService::class);
        $status = $health->status();
        if (($status['silent_alarm'] ?? false) === true) {
            $reasons[] = 'heartbeat_stale';
        }

        $payload = [
            'action' => 'ensure-launchd',
            'label' => $label,
            'platform' => 'Darwin',
            'agent_present' => $agentPresent,
            'last_exit_code' => $exitCode,
            'new_fatal_blocks' => $fatal['new_blocks'],
            'total_fatal_blocks' => $fatal['total_blocks'],
            'heartbeat_silent_alarm' => (bool) ($status['silent_alarm'] ?? false),
            'unhealthy_reasons' => $reasons,
        ];

        if ($reasons === []) {
            $payload['status'] = 'healthy';

            return $this->emit($payload);
        }

        $payload['status'] = 'unhealthy';
        $payload['heal'] = 'reinstalling';
        try {
            $exit = $this->callSilent('atlas:scheduler:install-launchd', [
                '--label' => $label,
            ]);
            // Kickstart after reinstall so the patient runs immediately.
            $this->kickstart($label);
            $payload['reinstall_exit_code'] = $exit;
            $payload['status'] = $exit === 0 ? 'reinstalled' : 'reinstall_failed';
            $payload['healed'] = $exit === 0;
        } catch (\Throwable $e) {
            $payload['status'] = 'reinstall_error';
            $payload['healed'] = false;
            $payload['error'] = substr($e->getMessage(), 0, 160);
        }

        return $this->emit($payload);
    }

    /**
     * Testable seam — override or set ATLAS_ENSURE_LAUNCHCTL_BIN / mock via subclass.
     */
    protected function shell(string $command): string
    {
        $bin = getenv('ATLAS_ENSURE_LAUNCHCTL_BIN');
        if (is_string($bin) && $bin !== '') {
            // Test harness: bin receives the full command as argv[1].
            $wrapped = escapeshellarg($bin).' '.escapeshellarg($command);

            return (string) @shell_exec($wrapped.' 2>/dev/null');
        }

        return (string) @shell_exec($command.' 2>/dev/null');
    }

    protected function agentPresent(string $label): bool
    {
        $list = $this->shell('launchctl list');

        return str_contains($list, $label);
    }

    protected function lastExitCode(string $label): ?int
    {
        $uid = function_exists('posix_getuid') ? (int) posix_getuid() : 0;
        $print = $this->shell('launchctl print gui/'.$uid.'/'.$label);
        if ($print === '') {
            return null;
        }
        if (preg_match('/last exit code\s*=\s*(-?\d+)/', $print, $m)) {
            return (int) $m[1];
        }
        // "last exit code = (never exited)" etc. → treat as unknown/null (not unhealthy by itself).
        return null;
    }

    protected function kickstart(string $label): void
    {
        $uid = function_exists('posix_getuid') ? (int) posix_getuid() : 0;
        $this->shell('launchctl kickstart -k gui/'.$uid.'/'.$label);
    }

    /**
     * @return array{new_blocks:int,total_blocks:int}
     */
    protected function newFatalBlocks(): array
    {
        $errLog = $this->errLogPath();
        $statePath = $this->fatalStatePath();
        $state = [];
        if (is_file($statePath)) {
            $decoded = json_decode((string) file_get_contents($statePath), true);
            $state = is_array($decoded) ? $decoded : [];
        }
        $offset = (int) ($state['err_log_offset'] ?? 0);
        $prev = (int) ($state['err_log_fatal_blocks'] ?? 0);

        if (! is_file($errLog)) {
            return ['new_blocks' => 0, 'total_blocks' => $prev];
        }
        $size = (int) filesize($errLog);
        if ($offset > $size) {
            $offset = 0;
            $prev = 0;
        }
        $fh = fopen($errLog, 'rb');
        if ($fh === false) {
            return ['new_blocks' => 0, 'total_blocks' => $prev];
        }
        fseek($fh, $offset);
        $chunk = stream_get_contents($fh) ?: '';
        fclose($fh);
        $new = substr_count($chunk, 'PHP Fatal error');
        $total = $prev + $new;
        @mkdir(dirname($statePath), 0775, true);
        file_put_contents($statePath, json_encode([
            'err_log_offset' => $size,
            'err_log_fatal_blocks' => $total,
            'updated_at' => UtcIsoTimestamp::now(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);

        return ['new_blocks' => $new, 'total_blocks' => $total];
    }

    protected function errLogPath(): string
    {
        $override = getenv('ATLAS_ENSURE_ERR_LOG');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return storage_path('atlas/scheduler/launchd.err.log');
    }

    protected function fatalStatePath(): string
    {
        $override = getenv('ATLAS_ENSURE_FATAL_STATE');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return storage_path('atlas/scheduler/ensure-launchd-fatal-state.json');
    }

    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }
        foreach ($payload as $k => $v) {
            $this->components->twoColumnDetail((string) $k, is_scalar($v) ? (string) $v : json_encode($v));
        }

        return self::SUCCESS;
    }
}
