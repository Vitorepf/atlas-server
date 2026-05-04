<?php

namespace App\Services\MacAgent;

use App\Models\AiJob;
use App\Models\AtlasHostStatus;
use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerEvent;
use App\Models\AtlasPowerSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MacAgentService
{
    public const HOST_KEY = 'local-macbook';
    public const POWER_HELPER_LABEL = 'com.atlas.power-helper';
    public const POWER_HELPER_PLIST = '/Library/LaunchDaemons/com.atlas.power-helper.plist';
    public const MAX_REMOTE_SESSION_HOURS = 12;
    public const DEFAULT_SLEEP_AFTER_IDLE_SECONDS = 900;
    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';
    private const CAFFEINATE_LABEL_PREFIX = 'com.atlas.caffeinate.';

    /**
     * @return array<string,mixed>
     */
    public function status(bool $refresh = true): array
    {
        if (! Schema::hasTable('atlas_power_sessions') || ! Schema::hasTable('atlas_host_status')) {
            return [
                'host_key' => self::HOST_KEY,
                'status' => 'not_installed',
                'host' => null,
                'active_sessions' => [],
                'power_helper' => $this->powerHelperStatus(),
                'wake_schedule' => $this->wakeScheduleStatus(),
                'generated_at' => now()->toJSON(),
            ];
        }

        if ($refresh && Schema::hasTable('atlas_host_status')) {
            $this->heartbeat();
        }

        $this->reconcileActiveSessions();

        $host = AtlasHostStatus::query()->where('host_key', self::HOST_KEY)->first();
        $activeSessions = $this->activeSessionsQuery()->orderByDesc('created_at')->limit(10)->get();

        return [
            'host_key' => self::HOST_KEY,
            'status' => $this->operationalStatus($host, $activeSessions->count()),
            'host' => $host?->toArray(),
            'active_sessions' => $activeSessions->map(fn (AtlasPowerSession $session): array => $this->sessionPayload($session))->values()->all(),
            'power_helper' => $this->powerHelperStatus(),
            'wake_schedule' => $this->wakeScheduleStatus(),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array{installed:bool,loaded:?bool,running:?bool,needs_install:bool,plist:string,label:string,last_checked_at:?string,last_success_at:?string,install_command:string,last_error:?string}
     */
    public function powerHelperStatus(): array
    {
        $installed = is_file(self::POWER_HELPER_PLIST);
        $loaded = null;
        $running = null;
        $lastError = null;

        if (PHP_OS_FAMILY === 'Darwin') {
            $process = new Process(['launchctl', 'print', 'system/'.self::POWER_HELPER_LABEL], base_path(), null, null, 5);
            $process->run();
            $loaded = $process->isSuccessful();
            $running = $process->isSuccessful() && str_contains($process->getOutput(), 'state = running');
            if (! $process->isSuccessful()) {
                $lastError = trim($process->getErrorOutput() ?: $process->getOutput()) ?: null;
            }
        }

        $lastCheck = $this->latestPowerHelperEvent();
        $lastSuccess = $this->latestPowerHelperEvent('power_helper_check_succeeded');

        return [
            'installed' => $installed,
            'loaded' => $loaded,
            'running' => $running,
            'needs_install' => ! $installed,
            'plist' => self::POWER_HELPER_PLIST,
            'label' => self::POWER_HELPER_LABEL,
            'last_checked_at' => $lastCheck?->occurred_at?->toJSON(),
            'last_success_at' => $lastSuccess?->occurred_at?->toJSON(),
            'install_command' => 'bash scripts/install-power-helper-launch-daemon.sh',
            'last_error' => $lastError,
        ];
    }

    /**
     * @return array{available:bool,scheduled:bool,raw:?string,next_wake_at:?string}
     */
    public function wakeScheduleStatus(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('pmset')) {
            return ['available' => false, 'scheduled' => false, 'raw' => null, 'next_wake_at' => null];
        }

        $process = new Process(['pmset', '-g', 'sched'], base_path(), null, null, 5);
        $process->run();
        $raw = trim($process->getOutput()."\n".$process->getErrorOutput());

        return [
            'available' => $process->isSuccessful(),
            'scheduled' => str_contains($raw, 'wakeorpoweron') || $this->hasConfirmedAtlasWake(),
            'raw' => $raw !== '' ? $raw : null,
            'next_wake_at' => $this->nextWakeMetadata(),
        ];
    }

    public function heartbeat(bool $reconcile = true): AtlasHostStatus
    {
        if (! Schema::hasTable('atlas_host_status') || ! Schema::hasTable('atlas_power_sessions')) {
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
        $activeJobs = Schema::hasTable('ai_jobs')
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
        if (! Schema::hasTable('atlas_power_sessions')) {
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
        if (! Schema::hasTable('atlas_power_sessions')) {
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
     * @return array{checked:int,restarted:int,failed:int,skipped:int}
     */
    public function reconcileActiveSessions(): array
    {
        if (! Schema::hasTable('atlas_power_sessions')) {
            return ['checked' => 0, 'restarted' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $summary = ['checked' => 0, 'restarted' => 0, 'failed' => 0, 'skipped' => 0];

        /** @var \Illuminate\Support\Collection<int,AtlasPowerSession> $sessions */
        $sessions = $this->activeSessionsQuery()->get();
        foreach ($sessions as $session) {
            $summary['checked']++;
            $alive = $this->caffeinateAlive($session->caffeinate_pid, $this->caffeinateLabel($session));

            if ($alive === true || $alive === null) {
                $summary['skipped']++;
                continue;
            }

            $previousPid = $session->caffeinate_pid;
            $previousLabel = $this->caffeinateLabel($session);
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
        if (! Schema::hasTable('atlas_power_sessions')) {
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
     * @return array{expired:array<string,mixed>,reconciled:array<string,int>,started:array<int,array<string,mixed>>,sleep_after_idle:bool}
     */
    public function runMaintenanceCycle(bool $allowSleepAfterIdle = true): array
    {
        $reconciled = $this->reconcileActiveSessions();
        $this->heartbeat(reconcile: false);
        $expired = $this->expireSessionsDetailed();
        $started = $this->startDueMaintenanceWindows();
        $sleepAfterIdle = false;

        if ($allowSleepAfterIdle && ($expired['maintenance_expired'] ?? 0) > 0) {
            $sleepAfterIdle = $this->sleepAfterIdle('maintenance_window_completed');
        }

        return [
            'expired' => $expired,
            'reconciled' => $reconciled,
            'started' => $started,
            'sleep_after_idle' => $sleepAfterIdle,
        ];
    }

    public function sleepAfterIdle(string $reason, int $minIdleSeconds = self::DEFAULT_SLEEP_AFTER_IDLE_SECONDS): bool
    {
        $activeJobs = $this->activeAiJobsCount();
        $activeSessions = Schema::hasTable('atlas_power_sessions') ? $this->activeSessionsQuery()->count() : 0;
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
        if (! $window->enabled || PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('pmset')) {
            return false;
        }

        $wakeAt = $this->nextWakeAt($window);
        $process = new Process(['pmset', 'schedule', 'wakeorpoweron', $wakeAt->format('m/d/y H:i:s')], base_path(), null, null, 10);
        $process->run();
        $ok = $process->isSuccessful();

        $window->update([
            'last_scheduled_at' => now(),
            'metadata' => array_merge($window->metadata ?? [], [
                'next_wake_at' => $wakeAt->toJSON(),
                'pmset_ok' => $ok,
                'pmset_output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
                'power_helper_required' => ! $ok && str_contains($process->getErrorOutput(), 'must be run as root'),
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
        if (! Schema::hasTable('atlas_maintenance_windows') || ! Schema::hasTable('atlas_power_sessions')) {
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
        if (! Schema::hasTable('atlas_power_events')) {
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
        if (! Schema::hasTable('atlas_power_events')) {
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
        return [
            'id' => $session->id,
            'kind' => $session->kind,
            'status' => $session->status,
            'reason' => $session->reason,
            'source' => $session->source,
            'ai_job_id' => $session->ai_job_id,
            'caffeinate_pid' => $session->caffeinate_pid,
            'caffeinate_label' => $this->caffeinateLabel($session),
            'caffeinate_alive' => $this->caffeinateAlive($session->caffeinate_pid, $this->caffeinateLabel($session)),
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
        return Schema::hasTable('ai_jobs')
            ? DB::table('ai_jobs')->where('status', 'processing')->count()
            : 0;
    }

    /**
     * @return array{pid:?int,label:?string,method:string,error?:?string}
     */
    private function startCaffeinate(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('caffeinate')) {
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
        if (PHP_OS_FAMILY !== 'Darwin') {
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
        if (PHP_OS_FAMILY !== 'Darwin') {
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
        if (PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('pmset')) {
            return false;
        }

        $process = new Process(['pmset', 'sleepnow'], base_path(), null, null, 10);
        $process->run();

        return $process->isSuccessful();
    }

    private function userIdleSeconds(): ?int
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('ioreg')) {
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

    private function latestPowerHelperEvent(?string $type = null): ?AtlasPowerEvent
    {
        if (! Schema::hasTable('atlas_power_events')) {
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
        if (PHP_OS_FAMILY !== 'Darwin' || ! $this->binaryAvailable('pmset')) {
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
        if (! Schema::hasTable('atlas_maintenance_windows')) {
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
        if (! Schema::hasTable('atlas_maintenance_windows')) {
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
