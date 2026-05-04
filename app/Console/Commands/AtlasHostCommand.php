<?php

namespace App\Console\Commands;

use App\Models\AtlasMaintenanceWindow;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasHostCommand extends Command
{
    protected $signature = 'atlas:host
        {action=status : status, doctor, bootstrap, hold, release, sleep-now, schedule-wake, expire, cleanup-caffeinate}
        {--session= : Power session id}
        {--reason=Atlas host command : Reason for hold/sleep}
        {--kind=remote_manual : Power session kind}
        {--ttl=4h : Hold duration: 30m, 4h, 12h}
        {--name=Janela Atlas : Maintenance window name}
        {--wake-time=02:00 : Maintenance wake time HH:MM}
        {--duration=120 : Maintenance duration minutes}
        {--timezone=America/Sao_Paulo : Maintenance timezone}
        {--dry-run : Show bootstrap actions without changing local launchd/windows}
        {--install-power-helper : During bootstrap, attempt the admin-password Power Helper installer}
        {--skip-user-agent : During bootstrap, do not install/reload the user LaunchAgent}
        {--skip-window : During bootstrap, do not create/update the default maintenance window}
        {--json : Print JSON}';

    protected $description = 'Manage Atlas Mac Agent power sessions, status and maintenance wake schedules.';

    public function handle(MacAgentService $agent): int
    {
        $action = (string) $this->argument('action');

        $result = match ($action) {
            'status', 'doctor' => $agent->status(refresh: true),
            'bootstrap' => $this->bootstrap($agent),
            'hold' => [
                'session' => $agent->sessionPayload($agent->startSession(
                    kind: (string) $this->option('kind'),
                    reason: (string) $this->option('reason'),
                    expiresAt: now()->addSeconds($this->ttlSeconds((string) $this->option('ttl'))),
                    source: 'atlas_host_cli',
                )),
            ],
            'release' => [
                'session' => $agent->stopSession((string) $this->option('session'), 'cli_release')?->toArray(),
            ],
            'sleep-now' => [
                'ok' => $agent->sleepNow('cli_sleep_now'),
            ],
            'schedule-wake' => [
                'window' => $this->scheduleWake($agent)->toArray(),
            ],
            'expire' => [
                'expired' => $agent->expireSessions(),
            ],
            'cleanup-caffeinate' => [
                'caffeinate_cleanup' => $agent->cleanupOrphanCaffeinateJobs(),
                'status' => $agent->status(refresh: true),
            ],
            default => ['error' => "Acao invalida: {$action}"],
        };

        $this->printResult($result);

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }

    private function scheduleWake(MacAgentService $agent): AtlasMaintenanceWindow
    {
        return $agent->upsertMaintenanceWindow([
            'name' => (string) $this->option('name'),
            'wake_time' => (string) $this->option('wake-time'),
            'duration_minutes' => (int) $this->option('duration'),
            'timezone' => (string) $this->option('timezone'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrap(MacAgentService $agent): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $steps = [];
        $before = $agent->status(refresh: true);

        if (! (bool) $this->option('skip-user-agent')) {
            $macAgentReady = (bool) data_get($before, 'mac_agent.ready', false);
            $steps[] = $this->bootstrapStep(
                name: 'mac_agent_launch_agent',
                needed: ! $macAgentReady,
                command: 'cd '.base_path().' && bash scripts/install-mac-agent-launch-agent.sh',
                apply: ! $dryRun && ! $macAgentReady,
            );
        }

        if (! (bool) $this->option('skip-window')) {
            $steps[] = $this->bootstrapWindowStep($agent, $dryRun);
        }

        $powerHelperReady = (bool) data_get($agent->status(refresh: true), 'power_helper.ready', false);
        $powerHelperCommand = 'cd '.base_path().' && bash scripts/install-power-helper-launch-daemon.sh';
        if ($powerHelperReady) {
            $steps[] = [
                'name' => 'power_helper_launch_daemon',
                'status' => 'ready',
                'needed' => false,
                'command' => $powerHelperCommand,
                'message' => 'Power Helper root ja esta pronto.',
            ];
        } elseif ((bool) $this->option('install-power-helper')) {
            $steps[] = $this->bootstrapStep(
                name: 'power_helper_launch_daemon',
                needed: true,
                command: $powerHelperCommand,
                apply: ! $dryRun,
                admin_required: true,
            );
        } else {
            $steps[] = [
                'name' => 'power_helper_launch_daemon',
                'status' => 'requires_admin',
                'needed' => true,
                'command' => $powerHelperCommand,
                'message' => 'Instalacao root requer senha de administrador. Rode o comando no Terminal ou use --install-power-helper.',
                'admin_required' => true,
            ];
        }

        $after = $agent->status(refresh: true);

        return [
            'ok' => (bool) data_get($after, 'readiness.ready_for_remote', false),
            'complete' => (bool) data_get($after, 'readiness.ready_for_background_jobs', false),
            'dry_run' => $dryRun,
            'readiness' => $after['readiness'] ?? null,
            'steps' => $steps,
            'next_actions' => $agent->readinessNextActions($after),
            'status' => $after,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrapWindowStep(MacAgentService $agent, bool $dryRun): array
    {
        $command = '/opt/homebrew/bin/php artisan atlas:host schedule-wake --name="'.(string) $this->option('name').'" --wake-time='.(string) $this->option('wake-time').' --duration='.(string) $this->option('duration').' --timezone='.(string) $this->option('timezone').' --json';

        if ($dryRun) {
            return [
                'name' => 'maintenance_window',
                'status' => 'planned',
                'needed' => true,
                'command' => $command,
            ];
        }

        $window = $this->scheduleWake($agent);

        return [
            'name' => 'maintenance_window',
            'status' => (bool) data_get($window->metadata, 'pmset_ok', false) ? 'ready' : 'created_wake_pending',
            'needed' => true,
            'command' => $command,
            'window' => $window->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrapStep(string $name, bool $needed, string $command, bool $apply, bool $admin_required = false): array
    {
        if (! $needed) {
            return [
                'name' => $name,
                'status' => 'ready',
                'needed' => false,
                'command' => $command,
                'admin_required' => $admin_required,
            ];
        }

        if (! $apply) {
            return [
                'name' => $name,
                'status' => 'planned',
                'needed' => true,
                'command' => $command,
                'admin_required' => $admin_required,
            ];
        }

        $process = Process::fromShellCommandline($command, base_path(), null, null, 90);
        $process->run();

        return [
            'name' => $name,
            'status' => $process->isSuccessful() ? 'applied' : 'failed',
            'needed' => true,
            'command' => $command,
            'admin_required' => $admin_required,
            'exit_code' => $process->getExitCode(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }

    private function ttlSeconds(string $ttl): int
    {
        $ttl = trim($ttl);
        if (preg_match('/^(\\d+)m$/', $ttl, $match)) {
            return max(60, (int) $match[1] * 60);
        }
        if (preg_match('/^(\\d+)h$/', $ttl, $match)) {
            return max(60, (int) $match[1] * 3600);
        }

        return max(60, (int) $ttl);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function printResult(array $result): void
    {
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ((bool) $this->option('json')) {
            $this->line($json ?: '{}');

            return;
        }

        if (isset($result['error'])) {
            $this->error((string) $result['error']);

            return;
        }

        $this->line($json ?: '{}');
    }
}
