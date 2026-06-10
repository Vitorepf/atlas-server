<?php

namespace App\Services\MacAgent;

use App\Models\AiJob;
use App\Models\AtlasHostStatus;
use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerEvent;
use App\Models\AtlasPowerSession;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MacAgentService
{
    public const HOST_KEY = 'local-macbook';

    public const MAC_AGENT_LABEL = 'com.atlas.mac-agent';

    public const POWER_HELPER_LABEL = 'com.atlas.power-helper';

    public const POWER_HELPER_PLIST = '/Library/LaunchDaemons/com.atlas.power-helper.plist';

    public const MAX_REMOTE_SESSION_HOURS = 12;

    public const DEFAULT_SLEEP_AFTER_IDLE_SECONDS = 900;

    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    private const CAFFEINATE_LABEL_PREFIX = 'com.atlas.caffeinate.';

    private const LOW_BATTERY_BACKGROUND_BLOCK_PERCENT = 30;

    private const POWER_HELPER_STALE_AFTER_MINUTES = 15;

    /**
     * @return array<string,mixed>
     */
    public function status(bool $refresh = true): array
    {
        if (! DatabaseTableAvailability::all(['atlas_power_sessions', 'atlas_host_status'])) {
            $macAgent = $this->macAgentSupervisorStatus();
            $powerHelper = $this->powerHelperStatus();
            $caffeinateRuntime = $this->caffeinateRuntimeStatus();
            $wakeSchedule = $this->wakeScheduleStatus();

            return [
                'host_key' => self::HOST_KEY,
                'status' => 'not_installed',
                'host' => null,
                'active_sessions' => [],
                'mac_agent' => $macAgent,
                'power_helper' => $powerHelper,
                'caffeinate_runtime' => $caffeinateRuntime,
                'wake_schedule' => $wakeSchedule,
                'readiness' => $this->readinessStatus('not_installed', null, 0, $macAgent, $powerHelper, $caffeinateRuntime, $wakeSchedule),
                'generated_at' => now()->toJSON(),
            ];
        }

        if ($refresh && DatabaseTableAvailability::has('atlas_host_status') && $this->isDarwinRuntime()) {
            $this->heartbeat();
        } elseif ($this->isDarwinRuntime()) {
            $this->reconcileActiveSessions();
        }

        $host = AtlasHostStatus::query()->where('host_key', self::HOST_KEY)->first();
        $activeSessions = $this->activeSessionsQuery()->orderByDesc('created_at')->limit(10)->get();
        $status = $this->operationalStatus($host, $activeSessions->count());
        $macAgent = $this->runtimeSnapshot($host, 'mac_agent') ?? $this->macAgentSupervisorStatus();
        $powerHelper = $this->runtimeSnapshot($host, 'power_helper') ?? $this->powerHelperStatus();
        $caffeinateRuntime = $this->runtimeSnapshot($host, 'caffeinate_runtime') ?? $this->caffeinateRuntimeStatus();
        $wakeSchedule = $this->runtimeSnapshot($host, 'wake_schedule') ?? $this->wakeScheduleStatus();

        return [
            'host_key' => self::HOST_KEY,
            'status' => $status,
            'host' => $host?->toArray(),
            'active_sessions' => $activeSessions->map(fn (AtlasPowerSession $session): array => $this->sessionPayload($session))->values()->all(),
            'mac_agent' => $macAgent,
            'power_helper' => $powerHelper,
            'caffeinate_runtime' => $caffeinateRuntime,
            'wake_schedule' => $wakeSchedule,
            'readiness' => $this->readinessStatus($status, $host, $activeSessions->count(), $macAgent, $powerHelper, $caffeinateRuntime, $wakeSchedule),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function macAgentSupervisorStatus(): array
    {
        $plist = $this->macAgentPlistPath();
        $installed = is_file($plist);
        $loaded = null;
        $running = null;
        $pid = null;
        $lastError = null;

        if (PHP_OS_FAMILY === 'Darwin') {
            $process = new Process(['launchctl', 'print', 'gui/'.$this->userId().'/'.self::MAC_AGENT_LABEL], base_path(), null, null, 5);
            $process->run();
            $loaded = $process->isSuccessful();
            $output = trim($process->getOutput());
            $running = $process->isSuccessful() && str_contains($output, 'state = running');
            preg_match('/\\bpid = (\\d+)\\b/', $output, $match);
            $pid = isset($match[1]) ? (int) $match[1] : null;
            if (! $process->isSuccessful()) {
                $lastError = trim($process->getErrorOutput() ?: $process->getOutput()) ?: null;
            }
        }

        $ready = $installed && $loaded === true;

        return [
            'installed' => $installed,
            'loaded' => $loaded,
            'running' => $running,
            'ready' => $ready,
            'needs_install' => ! $installed || $loaded === false,
            'pid' => $pid,
            'plist' => $plist,
            'label' => self::MAC_AGENT_LABEL,
            'launchctl_domain' => 'gui/'.$this->userId().'/'.self::MAC_AGENT_LABEL,
            'install_command' => 'cd '.base_path().' && bash scripts/install-mac-agent-launch-agent.sh',
            'uninstall_command' => 'cd '.base_path().' && bash scripts/uninstall-mac-agent-launch-agent.sh',
            'log_paths' => [
                'stdout' => storage_path('logs/'.self::MAC_AGENT_LABEL.'.log'),
                'stderr' => storage_path('logs/'.self::MAC_AGENT_LABEL.'.err.log'),
            ],
            'next_action' => $this->macAgentNextAction($installed, $loaded),
            'last_error' => $lastError,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function powerHelperStatus(): array
    {
        $installed = is_file(self::POWER_HELPER_PLIST);
        $loaded = null;
        $running = null;
        $lastError = null;
        $printOutput = null;

        if (PHP_OS_FAMILY === 'Darwin') {
            $process = new Process(['launchctl', 'print', 'system/'.self::POWER_HELPER_LABEL], base_path(), null, null, 5);
            $process->run();
            $loaded = $process->isSuccessful();
            $printOutput = trim($process->getOutput());
            $running = $process->isSuccessful() && str_contains($printOutput, 'state = running');
            if (! $process->isSuccessful()) {
                $lastError = trim($process->getErrorOutput() ?: $process->getOutput()) ?: null;
            }
        }

        $lastCheck = $this->latestPowerHelperEvent();
        $lastSuccess = $this->latestPowerHelperEvent('power_helper_check_succeeded');
        $lastSuccessFresh = $lastSuccess?->occurred_at?->greaterThanOrEqualTo(now()->subMinutes(self::POWER_HELPER_STALE_AFTER_MINUTES)) ?? false;
        $ready = $installed && $loaded === true && $lastSuccessFresh;
        $sudoWithoutPassword = $this->sudoAvailableWithoutPassword();
        $installCommand = 'cd '.base_path().' && bash scripts/install-power-helper-launch-daemon.sh';

        return [
            'installed' => $installed,
            'loaded' => $loaded,
            'running' => $running,
            'ready' => $ready,
            'needs_install' => ! $installed || $loaded === false || $lastSuccess === null,
            'plist' => self::POWER_HELPER_PLIST,
            'label' => self::POWER_HELPER_LABEL,
            'last_checked_at' => $lastCheck?->occurred_at?->toJSON(),
            'last_success_at' => $lastSuccess?->occurred_at?->toJSON(),
            'last_success_fresh' => $lastSuccessFresh,
            'stale_after_minutes' => self::POWER_HELPER_STALE_AFTER_MINUTES,
            'install_command' => $installCommand,
            'uninstall_command' => 'cd '.base_path().' && bash scripts/uninstall-power-helper-launch-daemon.sh',
            'doctor_command' => '/opt/homebrew/bin/php artisan atlas:host doctor --json',
            'sudo_without_password' => $sudoWithoutPassword,
            'admin_password_required' => $sudoWithoutPassword === false,
            'launchctl_domain' => 'system/'.self::POWER_HELPER_LABEL,
            'log_paths' => [
                'stdout' => storage_path('logs/'.self::POWER_HELPER_LABEL.'.log'),
                'stderr' => storage_path('logs/'.self::POWER_HELPER_LABEL.'.err.log'),
            ],
            'next_action' => $this->powerHelperNextAction($installed, $loaded, $lastSuccessFresh, $lastSuccess !== null),
            'last_error' => $lastError,
        ];
    }

    /**
     * @return array{available:bool,scheduled:bool,atlas_confirmed:bool,system_has_wakeorpoweron:bool,raw:?string,next_wake_at:?string}
     */
    public function wakeScheduleStatus(): array
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('pmset')) {
            return [
                'available' => false,
                'scheduled' => false,
                'atlas_confirmed' => false,
                'system_has_wakeorpoweron' => false,
                'raw' => null,
                'next_wake_at' => null,
            ];
        }

        $process = new Process(['pmset', '-g', 'sched'], base_path(), null, null, 5);
        $process->run();
        $raw = trim($process->getOutput()."\n".$process->getErrorOutput());
        $atlasConfirmed = $this->hasConfirmedAtlasWake();

        return [
            'available' => $process->isSuccessful(),
            'scheduled' => $atlasConfirmed,
            'atlas_confirmed' => $atlasConfirmed,
            'system_has_wakeorpoweron' => str_contains($raw, 'wakeorpoweron'),
            'raw' => $raw !== '' ? $raw : null,
            'next_wake_at' => $this->nextWakeMetadata(),
        ];
    }

    /**
     * @param  array<string,mixed>  $powerHelper
     * @param  array<string,mixed>  $caffeinateRuntime
     * @param  array<string,mixed>  $wakeSchedule
     * @return array<string,mixed>
     */
    public function readinessStatus(
        string $status,
        ?AtlasHostStatus $host,
        int $activeSessions,
        array $macAgent,
        array $powerHelper,
        array $caffeinateRuntime,
        array $wakeSchedule,
    ): array {
        $blockers = [];
        $warnings = [];

        $agentOnline = ! in_array($status, ['not_installed', 'offline_or_sleeping'], true);
        $macAgentReady = ($macAgent['ready'] ?? false) === true;
        $caffeinateReady = ($caffeinateRuntime['available'] ?? false) === true && (int) ($caffeinateRuntime['orphan_count'] ?? 0) === 0;
        $powerHelperReady = ($powerHelper['ready'] ?? false) === true;
        $wakeReady = ($wakeSchedule['atlas_confirmed'] ?? false) === true;
        $onAcPower = $host?->on_ac_power;
        $batteryPercent = $host?->battery_percent;
        $powerReadyForBackgroundJobs = $onAcPower !== false
            || $batteryPercent === null
            || $batteryPercent > self::LOW_BATTERY_BACKGROUND_BLOCK_PERCENT;

        if (! $agentOnline) {
            $blockers[] = [
                'code' => $status === 'not_installed' ? 'mac_agent_not_migrated' : 'mac_agent_offline_or_sleeping',
                'severity' => 'critical',
                'message' => $status === 'not_installed'
                    ? 'Tabelas do Mac Agent ainda nao estao migradas.'
                    : 'Mac Agent offline, dormindo ou sem heartbeat recente.',
                'action' => '/opt/homebrew/bin/php artisan atlas:host doctor --json',
            ];
        }

        if (! $macAgentReady) {
            $blockers[] = [
                'code' => 'mac_agent_launch_agent_not_ready',
                'severity' => 'warning',
                'message' => (string) (($macAgent['next_action']['message'] ?? null) ?: 'LaunchAgent do Mac Agent ainda nao esta pronto.'),
                'action' => $macAgent['next_action']['command'] ?? $macAgent['install_command'] ?? null,
            ];
        }

        if (! ($caffeinateRuntime['available'] ?? false)) {
            $blockers[] = [
                'code' => 'caffeinate_unavailable',
                'severity' => 'critical',
                'message' => 'caffeinate nao esta disponivel no Mac.',
                'action' => 'which caffeinate',
            ];
        }

        if ((int) ($caffeinateRuntime['orphan_count'] ?? 0) > 0) {
            $warnings[] = [
                'code' => 'caffeinate_orphans_present',
                'severity' => 'warning',
                'message' => 'Existem retencoes de energia orfas no launchctl.',
                'action' => $caffeinateRuntime['cleanup_command'] ?? null,
            ];
        }

        if (! $powerHelperReady) {
            $blockers[] = [
                'code' => 'power_helper_not_ready',
                'severity' => 'warning',
                'message' => (string) (($powerHelper['next_action']['message'] ?? null) ?: 'Power Helper root ainda nao esta pronto.'),
                'action' => $powerHelper['next_action']['command'] ?? $powerHelper['install_command'] ?? null,
            ];
        }

        if (! $wakeReady) {
            $blockers[] = [
                'code' => 'atlas_wake_not_confirmed',
                'severity' => 'warning',
                'message' => 'Wake automatico do Atlas ainda nao esta confirmado no macOS.',
                'action' => $powerHelper['install_command'] ?? '/opt/homebrew/bin/php artisan atlas:host schedule-wake --json',
            ];
        }

        if (($wakeSchedule['system_has_wakeorpoweron'] ?? false) && ! $wakeReady) {
            $warnings[] = [
                'code' => 'system_wake_not_atlas',
                'severity' => 'info',
                'message' => 'O macOS tem wake agendado, mas nao confirmado como wake do Atlas.',
                'action' => 'pmset -g sched',
            ];
        }

        if ($onAcPower === false && $batteryPercent !== null && $batteryPercent <= self::LOW_BATTERY_BACKGROUND_BLOCK_PERCENT) {
            $blockers[] = [
                'code' => 'battery_too_low_for_background_jobs',
                'severity' => 'warning',
                'message' => "Mac na bateria com {$batteryPercent}%; jobs autonomos longos devem aguardar tomada ou mais carga.",
                'action' => 'Conectar o Mac na tomada antes de iniciar jobs autonomos longos.',
            ];
        } elseif ($onAcPower === false) {
            $warnings[] = [
                'code' => 'mac_not_on_ac_power',
                'severity' => 'info',
                'message' => $batteryPercent === null
                    ? 'Mac na bateria; fonte de energia sem percentual confirmado.'
                    : "Mac na bateria com {$batteryPercent}%; prefira tomada antes de jobs longos.",
                'action' => 'Conectar o Mac na tomada para execucoes autonomas longas.',
            ];
        }

        $readyForRemote = $agentOnline && $macAgentReady && $caffeinateReady;
        $readyForScheduledWake = $powerHelperReady && $wakeReady;
        $readyForBackgroundJobs = $readyForRemote && $readyForScheduledWake && $powerReadyForBackgroundJobs;
        $overall = $readyForBackgroundJobs
            ? 'ready'
            : ($readyForRemote ? 'remote_ready_wake_blocked' : 'blocked');
        $summary = $this->readinessSummary($overall, $readyForRemote, $readyForScheduledWake, $readyForBackgroundJobs);
        $nextAction = $this->readinessPrimaryAction($blockers, $warnings, $readyForBackgroundJobs, $readyForRemote);

        return [
            'overall' => $overall,
            'summary' => $summary,
            'ready_for_remote' => $readyForRemote,
            'ready_for_scheduled_wake' => $readyForScheduledWake,
            'ready_for_background_jobs' => $readyForBackgroundJobs,
            'power_ready_for_background_jobs' => $powerReadyForBackgroundJobs,
            'agent_online' => $agentOnline,
            'mac_agent_ready' => $macAgentReady,
            'caffeinate_ready' => $caffeinateReady,
            'power_helper_ready' => $powerHelperReady,
            'atlas_wake_confirmed' => $wakeReady,
            'on_ac_power' => $onAcPower,
            'battery_percent' => $batteryPercent,
            'active_power_sessions' => $activeSessions,
            'active_ai_jobs' => (int) ($host?->active_ai_jobs ?? 0),
            'next_action' => $nextAction,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public function heartbeat(bool $reconcile = true): AtlasHostStatus
    {
        if (! DatabaseTableAvailability::all(['atlas_host_status', 'atlas_power_sessions'])) {
            return new AtlasHostStatus([
                'host_key' => self::HOST_KEY,
                'status' => 'not_installed',
            ]);
        }

        if ($reconcile) {
            $this->reconcileActiveSessions();
        }

        $battery = $this->batteryState();
        $activeSessions = $this->activeSessionsQuery()->count();
        $activeJobs = DatabaseTableAvailability::has('ai_jobs')
            ? DB::table('ai_jobs')->where('status', 'processing')->count()
            : 0;

        return AtlasHostStatus::query()->updateOrCreate(
            ['host_key' => self::HOST_KEY],
            [
                'hostname' => $this->hostname(),
                'status' => 'online',
                'agent_available' => true,
                'caffeinate_available' => $this->binaryAvailable('caffeinate'),
                'pmset_available' => $this->binaryAvailable('pmset'),
                'docker_available' => $this->binaryAvailable('docker'),
                'on_ac_power' => $battery['on_ac_power'],
                'battery_percent' => $battery['battery_percent'],
                'active_power_sessions' => $activeSessions,
                'active_ai_jobs' => $activeJobs,
                'last_seen_at' => now(),
                'metadata' => [
                    'php_sapi' => PHP_SAPI,
                    'base_path' => base_path(),
                    'workdir' => config('atlas.ai.workdir'),
                    'runtime' => [
                        'mac_agent' => $this->macAgentSupervisorStatus(),
                        'power_helper' => $this->powerHelperStatus(),
                        'caffeinate_runtime' => $this->caffeinateRuntimeStatus(),
                        'wake_schedule' => $this->wakeScheduleStatus(),
                    ],
                ],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function startSession(
        string $kind,
        string $reason,
        ?Carbon $expiresAt = null,
        ?string $source = null,
        ?string $deviceId = null,
        ?AiJob $job = null,
        array $metadata = [],
    ): AtlasPowerSession {
        if (! DatabaseTableAvailability::has('atlas_power_sessions')) {
            return new AtlasPowerSession([
                'id' => (string) Str::uuid(),
                'host_key' => self::HOST_KEY,
                'kind' => $kind,
                'status' => 'untracked',
                'reason' => $reason,
                'source' => $source,
                'ai_job_id' => $job?->id,
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'metadata' => array_merge($metadata, ['storage_unavailable' => true]),
            ]);
        }

        $this->expireSessions();
        $expiresAt = $this->boundedExpiry($kind, $expiresAt);
        $caffeinate = $this->startCaffeinate();
        $pid = $caffeinate['pid'];
        $metadata = $this->withCaffeinateMetadata($metadata, $caffeinate);

        $session = AtlasPowerSession::query()->create([
            'host_key' => self::HOST_KEY,
            'kind' => $kind,
            'status' => 'active',
            'reason' => Str::limit($reason, 240, ''),
            'source' => $source,
            'created_by_device_id' => $deviceId,
            'ai_job_id' => $job?->id,
            'caffeinate_pid' => $pid,
            'started_at' => now(),
            'expires_at' => $expiresAt,
            'metadata' => $metadata,
        ]);

        $this->event('power_session_started', 'info', 'Power session started.', [
            'kind' => $kind,
            'reason' => $reason,
            'expires_at' => $expiresAt?->toJSON(),
            'caffeinate_pid' => $pid,
            'caffeinate_label' => $caffeinate['label'],
            'caffeinate_method' => $caffeinate['method'],
        ], $session, $job);
        $this->heartbeat();

        return $session;
    }

    public function stopSession(AtlasPowerSession|string $session, string $reason = 'manual_stop'): ?AtlasPowerSession
    {
        if (! DatabaseTableAvailability::has('atlas_power_sessions')) {
            return $session instanceof AtlasPowerSession ? $session : null;
        }

        $session = $session instanceof AtlasPowerSession
            ? $session
            : AtlasPowerSession::query()->whereKey($session)->first();

        if (! $session) {
            return null;
        }

        if ($session->status !== 'active') {
            return $session;
        }

        $this->stopCaffeinate($session->caffeinate_pid, $this->caffeinateLabel($session));
        $session->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => $reason,
        ]);

        $this->event('power_session_stopped', 'info', 'Power session stopped.', [
            'stop_reason' => $reason,
            'caffeinate_pid' => $session->caffeinate_pid,
            'caffeinate_label' => $this->caffeinateLabel($session),
        ], $session);
        $this->heartbeat();

        return $session->refresh();
    }

    public function expireSessions(): int
    {
        return $this->expireSessionsDetailed()['count'];
    }

    /**
     * @return array<string,mixed>
     */
    public function caffeinateRuntimeStatus(): array
    {
        $activeLabels = $this->activeCaffeinateLabels();
        $launchctlLabels = $this->launchctlCaffeinateLabels();
        $orphanLabels = array_values(array_diff($launchctlLabels, $activeLabels));

        return [
            'available' => $this->isDarwinRuntime() && $this->binaryAvailable('caffeinate'),
            'method' => 'launchctl_submit',
            'label_prefix' => self::CAFFEINATE_LABEL_PREFIX,
            'active_labels' => $activeLabels,
            'launchctl_labels' => $launchctlLabels,
            'orphan_labels' => $orphanLabels,
            'orphan_count' => count($orphanLabels),
            'cleanup_command' => '/opt/homebrew/bin/php artisan atlas:host cleanup-caffeinate --json',
        ];
    }

    /**
     * @return array{checked:int,removed:int,labels:array<int,string>,queued_for_host:bool}
     */
    public function cleanupOrphanCaffeinateJobs(): array
    {
        if (! $this->isDarwinRuntime()) {
            $runtime = $this->runtimeSnapshot(
                AtlasHostStatus::query()->where('host_key', self::HOST_KEY)->first(),
                'caffeinate_runtime',
            );
            $labels = array_values(array_filter((array) ($runtime['orphan_labels'] ?? []), 'is_string'));

            if ($labels !== []) {
                $this->event('caffeinate_cleanup_requested_pending_host', 'info', 'Caffeinate cleanup queued for Mac Agent host runtime.', [
                    'labels' => $labels,
                ]);
            }

            return [
                'checked' => count((array) ($runtime['launchctl_labels'] ?? [])),
                'removed' => 0,
                'labels' => $labels,
                'queued_for_host' => $labels !== [],
            ];
        }

        $runtime = $this->caffeinateRuntimeStatus();
        $labels = array_values(array_filter($runtime['orphan_labels'] ?? [], 'is_string'));
        $removed = 0;

        foreach ($labels as $label) {
            $this->stopCaffeinate(null, $label);
            $removed++;
        }

        if ($removed > 0) {
            $this->event('caffeinate_orphans_cleaned', 'info', 'Orphan caffeinate launchctl jobs were cleaned.', [
                'removed' => $removed,
                'labels' => $labels,
            ]);
        }

        return [
            'checked' => count($runtime['launchctl_labels'] ?? []),
            'removed' => $removed,
            'labels' => $labels,
            'queued_for_host' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function safeBootstrap(array $options = []): array
    {
        $before = $this->status(refresh: true);
        $cleanup = $this->cleanupOrphanCaffeinateJobs();
        $window = $this->upsertMaintenanceWindow([
            'name' => (string) ($options['name'] ?? 'Janela Atlas'),
            'wake_time' => (string) ($options['wake_time'] ?? '02:00'),
            'duration_minutes' => (int) ($options['duration_minutes'] ?? 120),
            'timezone' => (string) ($options['timezone'] ?? self::DEFAULT_TIMEZONE),
            'enabled' => true,
        ]);
        $after = $this->status(refresh: true);

        return [
            'ok' => (bool) data_get($after, 'readiness.ready_for_remote', false),
            'complete' => (bool) data_get($after, 'readiness.ready_for_background_jobs', false),
            'steps' => [
                [
                    'name' => 'mac_agent_launch_agent',
                    'status' => data_get($after, 'mac_agent.ready') ? 'ready' : 'needs_install',
                    'command' => data_get($after, 'mac_agent.install_command'),
                ],
                [
                    'name' => 'caffeinate_cleanup',
                    'status' => ($cleanup['removed'] ?? 0) > 0 ? 'cleaned' : 'ready',
                    'removed' => $cleanup['removed'] ?? 0,
                ],
                [
                    'name' => 'maintenance_window',
                    'status' => data_get($window->metadata, 'pmset_ok') ? 'ready' : 'created_wake_pending',
                    'window' => $window->toArray(),
                ],
                [
                    'name' => 'power_helper_launch_daemon',
                    'status' => data_get($after, 'power_helper.ready') ? 'ready' : 'requires_admin',
                    'command' => data_get($after, 'power_helper.install_command'),
                    'admin_required' => (bool) data_get($after, 'power_helper.admin_password_required', true),
                ],
            ],
            'next_actions' => $this->readinessNextActions($after),
            'before_readiness' => $before['readiness'] ?? null,
            'status' => $after,
        ];
    }

    /**
     * @param  array<string,mixed>  $status
     * @return array<int,array<string,mixed>>
     */
    public function readinessNextActions(array $status): array
    {
        return collect((array) data_get($status, 'readiness.blockers', []))
            ->map(fn (mixed $item): array => [
                'code' => (string) data_get($item, 'code', 'unknown'),
                'severity' => (string) data_get($item, 'severity', 'warning'),
                'message' => (string) data_get($item, 'message', ''),
                'command' => data_get($item, 'action'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{label:string,message:string,mode:string}
     */
    private function readinessSummary(
        string $overall,
        bool $readyForRemote,
        bool $readyForScheduledWake,
        bool $readyForBackgroundJobs,
    ): array {
        if ($readyForBackgroundJobs) {
            return [
                'label' => 'Completo',
                'message' => 'Modo remoto, wake automatico e jobs autonomos estao prontos.',
                'mode' => 'background_ready',
            ];
        }

        if ($readyForRemote && ! $readyForScheduledWake) {
            return [
                'label' => 'Remoto pronto',
                'message' => 'Voce ja consegue manter o Mac acordado pelo app; jobs autonomos ainda aguardam wake root confirmado.',
                'mode' => 'remote_ready',
            ];
        }

        return [
            'label' => $overall === 'blocked' ? 'Bloqueado' : 'Pendente',
            'message' => 'Mac Agent ainda precisa de preparacao antes de uso remoto confiavel.',
            'mode' => 'blocked',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $warnings
     * @return array{code:string,severity:string,message:string,command:mixed,kind:string}
     */
    private function readinessPrimaryAction(array $blockers, array $warnings, bool $readyForBackgroundJobs, bool $readyForRemote): array
    {
        if ($readyForBackgroundJobs) {
            return [
                'code' => 'none',
                'severity' => 'info',
                'message' => 'Nenhuma acao pendente.',
                'command' => null,
                'kind' => 'ready',
            ];
        }

        $priority = [
            'mac_agent_not_migrated' => 10,
            'mac_agent_offline_or_sleeping' => 20,
            'mac_agent_launch_agent_not_ready' => 30,
            'caffeinate_unavailable' => 40,
            'power_helper_not_ready' => 50,
            'atlas_wake_not_confirmed' => 60,
            'battery_too_low_for_background_jobs' => 70,
        ];

        $items = collect($blockers)
            ->sortBy(fn (array $item): int => $priority[(string) ($item['code'] ?? '')] ?? 100)
            ->values();

        $primary = $items->first();
        if (! is_array($primary) && ! empty($warnings)) {
            $primary = $warnings[0];
        }

        if (! is_array($primary)) {
            return [
                'code' => 'refresh_status',
                'severity' => 'info',
                'message' => 'Atualize o status do Mac Agent para confirmar prontidao.',
                'command' => '/opt/homebrew/bin/php artisan atlas:host doctor --json',
                'kind' => 'refresh',
            ];
        }

        $code = (string) ($primary['code'] ?? 'unknown');

        return [
            'code' => $code,
            'severity' => (string) ($primary['severity'] ?? 'warning'),
            'message' => (string) ($primary['message'] ?? ''),
            'command' => $primary['action'] ?? null,
            'kind' => $readyForRemote ? 'background_setup' : 'remote_setup',
        ];
    }

    /**
     * @return array{checked:int,restarted:int,failed:int,skipped:int}
     */
    public function reconcileActiveSessions(): array
    {
        if (! DatabaseTableAvailability::has('atlas_power_sessions')) {
            return ['checked' => 0, 'restarted' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $summary = ['checked' => 0, 'restarted' => 0, 'failed' => 0, 'skipped' => 0];

        /** @var Collection<int,AtlasPowerSession> $sessions */
        $sessions = $this->activeSessionsQuery()->get();
        foreach ($sessions as $session) {
            $summary['checked']++;
            $label = $this->caffeinateLabel($session);
            $alive = $this->caffeinateAlive($session->caffeinate_pid, $label);
            $method = data_get($session->metadata, 'caffeinate.method');
            $needsMaterialization = $this->isDarwinRuntime()
                && ($label === null || $method === 'unavailable' || $alive === false);

            if (! $needsMaterialization && ($alive === true || $alive === null)) {
                $summary['skipped']++;

                continue;
            }

            $previousPid = $session->caffeinate_pid;
            $previousLabel = $label;
            $this->stopCaffeinate($previousPid, $previousLabel);
            $caffeinate = $this->startCaffeinate();
            $newPid = $caffeinate['pid'];
            if ($newPid) {
                $metadata = $session->metadata ?? [];
                $metadata = $this->withCaffeinateMetadata($metadata, $caffeinate);
                $restarts = is_array($metadata['caffeinate_restarts'] ?? null) ? $metadata['caffeinate_restarts'] : [];
                $restarts[] = [
                    'previous_pid' => $previousPid,
                    'previous_label' => $previousLabel,
                    'new_pid' => $newPid,
                    'new_label' => $caffeinate['label'],
                    'restarted_at' => now()->toJSON(),
                ];
                $metadata['caffeinate_restarts'] = array_slice($restarts, -10);

                $session->update([
                    'caffeinate_pid' => $newPid,
                    'metadata' => $metadata,
                ]);

                $summary['restarted']++;
                $this->event('power_session_caffeinate_restarted', 'warning', 'Caffeinate process was restarted for an active power session.', [
                    'previous_pid' => $previousPid,
                    'previous_label' => $previousLabel,
                    'new_pid' => $newPid,
                    'new_label' => $caffeinate['label'],
                ], $session->refresh());

                continue;
            }

            $summary['failed']++;
            $this->event('power_session_caffeinate_restart_failed', 'error', 'Caffeinate process was dead and could not be restarted.', [
                'previous_pid' => $previousPid,
                'previous_label' => $previousLabel,
                'error' => $caffeinate['error'] ?? null,
            ], $session);
        }

        return $summary;
    }

    /**
     * @return array{count:int,maintenance_expired:int,sessions:array<int,string>}
     */
    public function expireSessionsDetailed(): array
    {
        if (! DatabaseTableAvailability::has('atlas_power_sessions')) {
            return ['count' => 0, 'maintenance_expired' => 0, 'sessions' => []];
        }

        $expired = $this->activeSessionsQuery()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $maintenanceExpired = 0;
        $ids = [];
        foreach ($expired as $session) {
            $ids[] = (string) $session->id;
            if ($session->kind === 'maintenance_window') {
                $maintenanceExpired++;
                $this->markMaintenanceWindowCompleted($session);
            }
            $this->stopSession($session, 'expired');
        }

        return [
            'count' => $expired->count(),
            'maintenance_expired' => $maintenanceExpired,
            'sessions' => $ids,
        ];
    }

    /**
     * @return array{expired:array<string,mixed>,reconciled:array<string,int>,caffeinate_cleanup:array<string,mixed>,started:array<int,array<string,mixed>>,sleep_after_idle:bool}
     */
    public function runMaintenanceCycle(bool $allowSleepAfterIdle = true): array
    {
        $reconciled = $this->reconcileActiveSessions();
        $caffeinateCleanup = $this->cleanupOrphanCaffeinateJobs();
        if ($this->hasPendingHostEvent('caffeinate_cleanup_requested_pending_host', 'caffeinate_cleanup_requested_pending_host_consumed')) {
            $this->event('caffeinate_cleanup_requested_pending_host_consumed', 'info', 'Pending host caffeinate cleanup request consumed by Mac Agent.', [
                'removed' => $caffeinateCleanup['removed'] ?? 0,
                'labels' => $caffeinateCleanup['labels'] ?? [],
            ]);
        }
        $this->heartbeat(reconcile: false);
        $expired = $this->expireSessionsDetailed();
        $started = $this->startDueMaintenanceWindows();
        $sleepAfterIdle = false;

        if ($allowSleepAfterIdle && ($expired['maintenance_expired'] ?? 0) > 0) {
            $sleepAfterIdle = $this->sleepAfterIdle('maintenance_window_completed');
        }

        if ($allowSleepAfterIdle && $this->hasPendingHostEvent('sleep_requested_pending_host', 'sleep_requested_pending_host_consumed')) {
            $this->event('sleep_requested_pending_host_consumed', 'info', 'Pending host sleep request consumed by Mac Agent.');
            $sleepAfterIdle = $this->sleepAfterIdle('mobile_sleep_now_pending', 0) || $sleepAfterIdle;
        }

        return [
            'expired' => $expired,
            'reconciled' => $reconciled,
            'caffeinate_cleanup' => $caffeinateCleanup,
            'started' => $started,
            'sleep_after_idle' => $sleepAfterIdle,
        ];
    }

    public function sleepAfterIdle(string $reason, int $minIdleSeconds = self::DEFAULT_SLEEP_AFTER_IDLE_SECONDS): bool
    {
        $activeJobs = $this->activeAiJobsCount();
        $activeSessions = DatabaseTableAvailability::has('atlas_power_sessions') ? $this->activeSessionsQuery()->count() : 0;
        $idleSeconds = $this->userIdleSeconds();

        if ($activeJobs > 0 || $activeSessions > 0 || $idleSeconds === null || $idleSeconds < $minIdleSeconds) {
            $this->event('sleep_after_idle_skipped', 'info', 'Sleep after idle skipped.', [
                'reason' => $reason,
                'active_jobs' => $activeJobs,
                'active_sessions' => $activeSessions,
                'idle_seconds' => $idleSeconds,
                'min_idle_seconds' => $minIdleSeconds,
            ]);

            return false;
        }

        $this->event('sleep_after_idle_requested', 'info', 'macOS sleep requested after idle maintenance.', [
            'reason' => $reason,
            'idle_seconds' => $idleSeconds,
            'min_idle_seconds' => $minIdleSeconds,
        ]);

        return $this->requestSystemSleep();
    }

    public function sleepNow(string $reason = 'mobile_requested'): bool
    {
        $this->expireSessions();
        $activeJobs = $this->activeAiJobsCount();

        if ($activeJobs > 0) {
            $this->event('sleep_blocked_active_jobs', 'warning', 'Sleep request blocked because jobs are active.', [
                'active_jobs' => $activeJobs,
                'reason' => $reason,
            ]);

            return false;
        }

        foreach ($this->activeSessionsQuery()->get() as $session) {
            $this->stopSession($session, 'sleep_now');
        }

        $this->event('sleep_requested', 'info', 'macOS sleep requested.', ['reason' => $reason]);

        if (! $this->isDarwinRuntime()) {
            $this->event('sleep_requested_pending_host', 'info', 'Sleep request queued for Mac Agent host runtime.', [
                'reason' => $reason,
            ]);

            return true;
        }

        return $this->requestSystemSleep();
    }

    public function upsertMaintenanceWindow(array $data): AtlasMaintenanceWindow
    {
        $name = (string) ($data['name'] ?? 'Janela Atlas');
        $existing = AtlasMaintenanceWindow::query()
            ->where('host_key', self::HOST_KEY)
            ->where('name', $name)
            ->first();
        $window = AtlasMaintenanceWindow::query()->updateOrCreate([
            'host_key' => self::HOST_KEY,
            'name' => $name,
        ], [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'timezone' => (string) ($data['timezone'] ?? self::DEFAULT_TIMEZONE),
            'wake_time' => (string) ($data['wake_time'] ?? '02:00'),
            'duration_minutes' => max(15, min(480, (int) ($data['duration_minutes'] ?? 120))),
            'days_of_week' => array_values((array) ($data['days_of_week'] ?? [])),
            'metadata' => array_merge($existing?->metadata ?? [], is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
        ]);

        $this->scheduleWakeForWindow($window);

        return $window->refresh();
    }

    public function scheduleWakeForWindow(AtlasMaintenanceWindow $window): bool
    {
        if (! $window->enabled || ! $this->isDarwinRuntime() || ! $this->binaryAvailable('pmset')) {
            return false;
        }

        $wakeAt = $this->nextWakeAt($window);
        $previousMetadata = $window->metadata ?? [];
        if (($previousMetadata['pmset_ok'] ?? null) === true && ($previousMetadata['next_wake_at'] ?? null) === $wakeAt->toJSON()) {
            return true;
        }

        $process = new Process(['pmset', 'schedule', 'wakeorpoweron', $wakeAt->format('m/d/y H:i:s')], base_path(), null, null, 10);
        $process->run();
        $ok = $process->isSuccessful();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $previouslyConfirmed = ($previousMetadata['pmset_ok'] ?? null) === true;
        $requiresHelper = ! $ok && str_contains($process->getErrorOutput(), 'must be run as root');
        $confirmed = $ok || ($requiresHelper && $previouslyConfirmed);

        $window->update([
            'last_scheduled_at' => $ok ? now() : $window->last_scheduled_at,
            'metadata' => array_merge($previousMetadata, [
                'next_wake_at' => $wakeAt->toJSON(),
                'pmset_ok' => $confirmed,
                'pmset_output' => $output,
                'power_helper_required' => $requiresHelper && ! $previouslyConfirmed,
                'power_helper' => $this->powerHelperStatus(),
            ]),
        ]);

        $this->event($ok ? 'maintenance_wake_scheduled' : 'maintenance_wake_schedule_failed', $ok ? 'info' : 'warning', $ok ? 'Wake scheduled.' : 'Wake schedule failed.', [
            'window_id' => $window->id,
            'wake_at' => $wakeAt->toJSON(),
        ]);

        return $ok;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function startDueMaintenanceWindows(): array
    {
        if (! DatabaseTableAvailability::all(['atlas_maintenance_windows', 'atlas_power_sessions'])) {
            return [];
        }

        $started = [];
        AtlasMaintenanceWindow::query()
            ->where('host_key', self::HOST_KEY)
            ->where('enabled', true)
            ->orderBy('wake_time')
            ->get()
            ->each(function (AtlasMaintenanceWindow $window) use (&$started): void {
                if (! $this->windowIsOpen($window) || $this->hasActiveMaintenanceSession($window)) {
                    return;
                }

                $endAt = $this->windowEndAt($window)->timezone(config('app.timezone', 'UTC'));
                $session = $this->startSession(
                    kind: 'maintenance_window',
                    reason: "Maintenance window: {$window->name}",
                    expiresAt: $endAt,
                    source: 'atlas_mac_agent',
                    metadata: [
                        'maintenance_window_id' => $window->id,
                        'sleep_after_idle' => true,
                        'duration_minutes' => $window->duration_minutes,
                    ],
                );

                $window->update(['last_started_at' => now()]);
                $this->event('maintenance_window_started', 'info', 'Maintenance window started.', [
                    'window_id' => $window->id,
                    'session_id' => $session->id,
                    'expires_at' => $endAt->toJSON(),
                ], $session);
                $started[] = $this->sessionPayload($session);
            });

        return $started;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentEvents(int $limit = 20): array
    {
        if (! DatabaseTableAvailability::has('atlas_power_events')) {
            return [];
        }

        return AtlasPowerEvent::query()
            ->where('host_key', self::HOST_KEY)
            ->orderByDesc('occurred_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (AtlasPowerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    public function event(string $type, string $severity, ?string $message = null, array $metadata = [], ?AtlasPowerSession $session = null, ?AiJob $job = null): AtlasPowerEvent
    {
        if (! DatabaseTableAvailability::has('atlas_power_events')) {
            return new AtlasPowerEvent([
                'host_key' => self::HOST_KEY,
                'event_type' => $type,
                'severity' => $severity,
                'message' => $message,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
        }

        return AtlasPowerEvent::query()->create([
            'host_key' => self::HOST_KEY,
            'power_session_id' => $session?->id,
            'ai_job_id' => $job?->id,
            'event_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function recordPowerHelperCheck(bool $runningAsRoot, int $scheduledWindows): void
    {
        $this->event(
            $runningAsRoot ? 'power_helper_check_succeeded' : 'power_helper_check_unprivileged',
            $runningAsRoot ? 'info' : 'warning',
            $runningAsRoot ? 'Power helper checked wake schedules as root.' : 'Power helper check ran without root privileges.',
            [
                'running_as_root' => $runningAsRoot,
                'scheduled_windows' => $scheduledWindows,
            ],
        );
    }

    public function sessionPayload(AtlasPowerSession $session): array
    {
        $label = $this->caffeinateLabel($session);

        return [
            'id' => $session->id,
            'kind' => $session->kind,
            'status' => $session->status,
            'reason' => $session->reason,
            'source' => $session->source,
            'ai_job_id' => $session->ai_job_id,
            'caffeinate_pid' => $session->caffeinate_pid,
            'caffeinate_label' => $label,
            'caffeinate_alive' => $this->sessionCaffeinateAlive($session, $label),
            'started_at' => $session->started_at?->toJSON(),
            'expires_at' => $session->expires_at?->toJSON(),
            'stopped_at' => $session->stopped_at?->toJSON(),
            'stop_reason' => $session->stop_reason,
            'metadata' => $session->metadata ?? [],
        ];
    }

    private function activeSessionsQuery()
    {
        return AtlasPowerSession::query()
            ->where('host_key', self::HOST_KEY)
            ->where('status', 'active');
    }

    private function activeAiJobsCount(): int
    {
        return DatabaseTableAvailability::has('ai_jobs')
            ? DB::table('ai_jobs')->where('status', 'processing')->count()
            : 0;
    }

    /**
     * @return array{pid:?int,label:?string,method:string,error?:?string}
     */
    private function startCaffeinate(): array
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('caffeinate')) {
            return ['pid' => null, 'label' => null, 'method' => 'unavailable', 'error' => null];
        }

        $label = self::CAFFEINATE_LABEL_PREFIX.Str::uuid()->toString();
        $process = new Process(['launchctl', 'submit', '-l', $label, '--', '/usr/bin/caffeinate', '-dims'], base_path(), null, null, 10);
        $process->run();
        if (! $process->isSuccessful()) {
            return [
                'pid' => null,
                'label' => $label,
                'method' => 'launchctl_submit',
                'error' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'launchctl submit failed',
            ];
        }

        usleep(100_000);
        $pid = $this->launchctlPid($label);

        return [
            'pid' => $pid,
            'label' => $label,
            'method' => 'launchctl_submit',
            'error' => $pid ? null : 'launchctl job started without observable pid',
        ];
    }

    private function stopCaffeinate(?int $pid, ?string $label = null): void
    {
        if (! $this->isDarwinRuntime()) {
            return;
        }

        if ($label) {
            $process = new Process(['launchctl', 'remove', $label], base_path(), null, null, 10);
            $process->run();
        }

        if ($pid) {
            $process = new Process(['kill', (string) $pid], base_path(), null, null, 10);
            $process->run();
        }
    }

    private function caffeinateAlive(?int $pid, ?string $label = null): ?bool
    {
        if (! $this->isDarwinRuntime()) {
            return null;
        }

        if ($label) {
            $labelPid = $this->launchctlPid($label);
            if ($labelPid !== null) {
                return $pid ? $labelPid === $pid : true;
            }
        }

        if (! $pid) {
            return null;
        }

        $process = new Process(['kill', '-0', (string) $pid], base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful();
    }

    private function sessionCaffeinateAlive(AtlasPowerSession $session, ?string $label): ?bool
    {
        $alive = $this->caffeinateAlive($session->caffeinate_pid, $label);
        if ($alive !== null || $this->isDarwinRuntime() || $label === null) {
            return $alive;
        }

        $runtime = $this->runtimeSnapshot(
            AtlasHostStatus::query()->where('host_key', self::HOST_KEY)->first(),
            'caffeinate_runtime',
        );

        if (! is_array($runtime)) {
            return null;
        }

        $labels = array_merge(
            array_filter((array) ($runtime['active_labels'] ?? []), 'is_string'),
            array_filter((array) ($runtime['launchctl_labels'] ?? []), 'is_string'),
        );

        return in_array($label, $labels, true);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array{pid:?int,label:?string,method:string,error?:?string}  $caffeinate
     * @return array<string,mixed>
     */
    private function withCaffeinateMetadata(array $metadata, array $caffeinate): array
    {
        $metadata['caffeinate'] = [
            'label' => $caffeinate['label'],
            'method' => $caffeinate['method'],
            'error' => $caffeinate['error'] ?? null,
            'started_at' => now()->toJSON(),
        ];

        return $metadata;
    }

    private function caffeinateLabel(AtlasPowerSession $session): ?string
    {
        $metadata = $session->metadata ?? [];
        $label = $metadata['caffeinate']['label'] ?? null;

        return is_string($label) && str_starts_with($label, self::CAFFEINATE_LABEL_PREFIX) ? $label : null;
    }

    /**
     * @return array<int,string>
     */
    private function activeCaffeinateLabels(): array
    {
        if (! DatabaseTableAvailability::has('atlas_power_sessions')) {
            return [];
        }

        return $this->activeSessionsQuery()
            ->get()
            ->map(fn (AtlasPowerSession $session): ?string => $this->caffeinateLabel($session))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function launchctlCaffeinateLabels(): array
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('launchctl')) {
            return [];
        }

        $process = new Process(['launchctl', 'print', 'gui/'.$this->userId()], base_path(), null, null, 10);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        preg_match_all('/com\\.atlas\\.caffeinate\\.[A-Za-z0-9-]+/', $process->getOutput(), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function launchctlPid(string $label): ?int
    {
        $process = new Process(['launchctl', 'print', 'gui/'.$this->userId().'/'.$label], base_path(), null, null, 5);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }

        preg_match('/\\bpid = (\\d+)\\b/', $process->getOutput(), $match);

        return isset($match[1]) ? (int) $match[1] : null;
    }

    private function userId(): int
    {
        if (function_exists('posix_getuid')) {
            return (int) posix_getuid();
        }

        $process = new Process(['id', '-u'], base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful() ? (int) trim($process->getOutput()) : 0;
    }

    private function requestSystemSleep(): bool
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('pmset')) {
            return false;
        }

        $process = new Process(['pmset', 'sleepnow'], base_path(), null, null, 10);
        $process->run();

        return $process->isSuccessful();
    }

    private function hasPendingHostEvent(string $pendingType, string $consumedType): bool
    {
        if (! DatabaseTableAvailability::has('atlas_power_events')) {
            return false;
        }

        $pending = AtlasPowerEvent::query()
            ->where('host_key', self::HOST_KEY)
            ->where('event_type', $pendingType)
            ->orderByDesc('occurred_at')
            ->first();

        if (! $pending) {
            return false;
        }

        $consumed = AtlasPowerEvent::query()
            ->where('host_key', self::HOST_KEY)
            ->where('event_type', $consumedType)
            ->where('occurred_at', '>=', $pending->occurred_at)
            ->exists();

        return ! $consumed;
    }

    private function userIdleSeconds(): ?int
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('ioreg')) {
            return null;
        }

        $process = Process::fromShellCommandline(
            "ioreg -c IOHIDSystem | awk '/HIDIdleTime/ { print int($NF / 1000000000); exit }'",
            base_path(),
            null,
            null,
            5,
        );
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }

        $idle = trim($process->getOutput());

        return ctype_digit($idle) ? (int) $idle : null;
    }

    private function binaryAvailable(string $binary): bool
    {
        $process = new Process(['which', $binary], base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    protected function isDarwinRuntime(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function runtimeSnapshot(?AtlasHostStatus $host, string $key): ?array
    {
        if ($this->isDarwinRuntime()) {
            return null;
        }

        $snapshot = data_get($host?->metadata ?? [], 'runtime.'.$key);

        return is_array($snapshot) ? $snapshot : null;
    }

    private function latestPowerHelperEvent(?string $type = null): ?AtlasPowerEvent
    {
        if (! DatabaseTableAvailability::has('atlas_power_events')) {
            return null;
        }

        $query = AtlasPowerEvent::query()
            ->where('host_key', self::HOST_KEY)
            ->whereIn('event_type', ['power_helper_check_succeeded', 'power_helper_check_unprivileged']);

        if ($type !== null) {
            $query->where('event_type', $type);
        }

        return $query->orderByDesc('occurred_at')->first();
    }

    private function sudoAvailableWithoutPassword(): ?bool
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('sudo')) {
            return null;
        }

        $process = new Process(['sudo', '-n', 'true'], base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful();
    }

    private function powerHelperNextAction(bool $installed, ?bool $loaded, bool $hasFreshRootCheck, bool $hasAnyRootCheck): array
    {
        if (! $installed) {
            return [
                'code' => 'install_power_helper',
                'severity' => 'warning',
                'message' => 'Instalar o Power Helper com senha de administrador para programar wake automatico.',
                'command' => 'cd '.base_path().' && bash scripts/install-power-helper-launch-daemon.sh',
            ];
        }

        if ($loaded === false) {
            return [
                'code' => 'reload_power_helper',
                'severity' => 'warning',
                'message' => 'Recarregar o LaunchDaemon do Power Helper.',
                'command' => 'cd '.base_path().' && bash scripts/install-power-helper-launch-daemon.sh',
            ];
        }

        if (! $hasFreshRootCheck) {
            return [
                'code' => $hasAnyRootCheck ? 'power_helper_check_stale' : 'wait_or_kickstart_power_helper',
                'severity' => 'info',
                'message' => $hasAnyRootCheck
                    ? 'Power Helper instalado, mas sem check root recente.'
                    : 'Aguardar o proximo ciclo ou executar kickstart para registrar o primeiro check root.',
                'command' => 'sudo launchctl kickstart -k system/'.self::POWER_HELPER_LABEL,
            ];
        }

        return [
            'code' => 'ready',
            'severity' => 'info',
            'message' => 'Power Helper instalado e com check root recente.',
            'command' => null,
        ];
    }

    private function macAgentNextAction(bool $installed, ?bool $loaded): array
    {
        if (! $installed) {
            return [
                'code' => 'install_mac_agent',
                'severity' => 'warning',
                'message' => 'Instalar o LaunchAgent do Mac Agent para manter heartbeat e limpeza automaticamente.',
                'command' => 'cd '.base_path().' && bash scripts/install-mac-agent-launch-agent.sh',
            ];
        }

        if ($loaded === false) {
            return [
                'code' => 'reload_mac_agent',
                'severity' => 'warning',
                'message' => 'Recarregar o LaunchAgent do Mac Agent.',
                'command' => 'cd '.base_path().' && bash scripts/install-mac-agent-launch-agent.sh',
            ];
        }

        return [
            'code' => 'ready',
            'severity' => 'info',
            'message' => 'Mac Agent LaunchAgent instalado e carregado.',
            'command' => null,
        ];
    }

    private function macAgentPlistPath(): string
    {
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid($this->userId())['dir'] ?? '') : '');

        return rtrim((string) $home, '/').'/Library/LaunchAgents/'.self::MAC_AGENT_LABEL.'.plist';
    }

    private function hostname(): ?string
    {
        $process = new Process(['hostname'], base_path(), null, null, 5);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @return array{on_ac_power:?bool,battery_percent:?int}
     */
    private function batteryState(): array
    {
        if (! $this->isDarwinRuntime() || ! $this->binaryAvailable('pmset')) {
            return ['on_ac_power' => null, 'battery_percent' => null];
        }

        $process = new Process(['pmset', '-g', 'batt'], base_path(), null, null, 5);
        $process->run();
        $output = $process->isSuccessful() ? $process->getOutput() : '';

        preg_match('/(\\d+)%/', $output, $match);

        return [
            'on_ac_power' => str_contains($output, 'AC Power'),
            'battery_percent' => isset($match[1]) ? (int) $match[1] : null,
        ];
    }

    private function boundedExpiry(string $kind, ?Carbon $expiresAt): ?Carbon
    {
        if ($kind !== 'remote_manual') {
            return $expiresAt;
        }

        $max = now()->addHours(self::MAX_REMOTE_SESSION_HOURS);
        if (! $expiresAt || $expiresAt->greaterThan($max)) {
            return $max;
        }

        return $expiresAt;
    }

    private function operationalStatus(?AtlasHostStatus $host, int $activeSessions): string
    {
        if (! $host || ! $host->last_seen_at || $host->last_seen_at->lessThan(now()->subMinutes(5))) {
            return 'offline_or_sleeping';
        }

        if ($activeSessions > 0) {
            return 'held_awake';
        }

        if (($host->active_ai_jobs ?? 0) > 0) {
            return 'running_jobs';
        }

        return 'online_idle';
    }

    private function nextWakeAt(AtlasMaintenanceWindow $window): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $window->wake_time));
        $now = now($window->timezone);
        $candidate = $now->copy()->setTime($hour, $minute)->subMinutes(5);
        if ($candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addDay();
        }

        $days = array_filter((array) ($window->days_of_week ?? []), fn (mixed $day): bool => is_int($day) || ctype_digit((string) $day));
        if ($days === []) {
            return $candidate->timezone(config('app.timezone', 'UTC'));
        }

        $allowed = array_map(fn (mixed $day): int => (int) $day, $days);
        while (! in_array((int) $candidate->dayOfWeekIso, $allowed, true)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->timezone(config('app.timezone', 'UTC'));
    }

    private function nextWakeMetadata(): ?string
    {
        if (! DatabaseTableAvailability::has('atlas_maintenance_windows')) {
            return null;
        }

        $next = AtlasMaintenanceWindow::query()
            ->where('host_key', self::HOST_KEY)
            ->where('enabled', true)
            ->get()
            ->map(fn (AtlasMaintenanceWindow $window): ?string => is_string($window->metadata['next_wake_at'] ?? null) ? $window->metadata['next_wake_at'] : null)
            ->filter()
            ->sort()
            ->first();

        return is_string($next) ? $next : null;
    }

    private function hasConfirmedAtlasWake(): bool
    {
        if (! DatabaseTableAvailability::has('atlas_maintenance_windows')) {
            return false;
        }

        return AtlasMaintenanceWindow::query()
            ->where('host_key', self::HOST_KEY)
            ->where('enabled', true)
            ->get()
            ->contains(fn (AtlasMaintenanceWindow $window): bool => ($window->metadata['pmset_ok'] ?? null) === true);
    }

    private function windowIsOpen(AtlasMaintenanceWindow $window): bool
    {
        $now = now($window->timezone);
        $start = $this->windowStartAt($window)->subMinutes(5);
        $end = $this->windowEndAt($window);

        return $now->betweenIncluded($start, $end) && $this->windowAllowedToday($window, $now);
    }

    private function windowStartAt(AtlasMaintenanceWindow $window): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $window->wake_time));

        return now($window->timezone)->setTime($hour, $minute);
    }

    private function windowEndAt(AtlasMaintenanceWindow $window): Carbon
    {
        return $this->windowStartAt($window)->addMinutes(max(15, (int) $window->duration_minutes));
    }

    private function windowAllowedToday(AtlasMaintenanceWindow $window, Carbon $now): bool
    {
        $days = array_filter((array) ($window->days_of_week ?? []), fn (mixed $day): bool => is_int($day) || ctype_digit((string) $day));
        if ($days === []) {
            return true;
        }

        return in_array((int) $now->dayOfWeekIso, array_map(fn (mixed $day): int => (int) $day, $days), true);
    }

    private function hasActiveMaintenanceSession(AtlasMaintenanceWindow $window): bool
    {
        return $this->activeSessionsQuery()
            ->where('kind', 'maintenance_window')
            ->get()
            ->contains(fn (AtlasPowerSession $session): bool => ($session->metadata['maintenance_window_id'] ?? null) === $window->id);
    }

    private function markMaintenanceWindowCompleted(AtlasPowerSession $session): void
    {
        $windowId = $session->metadata['maintenance_window_id'] ?? null;
        if (! is_string($windowId) || $windowId === '') {
            return;
        }

        $window = AtlasMaintenanceWindow::query()->whereKey($windowId)->first();
        if (! $window) {
            return;
        }

        $window->update(['last_completed_at' => now()]);
        $this->scheduleWakeForWindow($window->refresh());
        $this->event('maintenance_window_completed', 'info', 'Maintenance window completed.', [
            'window_id' => $window->id,
            'session_id' => $session->id,
        ], $session);
    }
}
