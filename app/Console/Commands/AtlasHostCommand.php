<?php

namespace App\Console\Commands;

use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerSession;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AtlasHostCommand extends Command
{
    protected $signature = 'atlas:host
        {action=status : status, doctor, hold, release, sleep-now, schedule-wake, expire}
        {--session= : Power session id}
        {--reason=Atlas host command : Reason for hold/sleep}
        {--kind=remote_manual : Power session kind}
        {--ttl=4h : Hold duration: 30m, 4h, 12h}
        {--name=Janela Atlas : Maintenance window name}
        {--wake-time=02:00 : Maintenance wake time HH:MM}
        {--duration=120 : Maintenance duration minutes}
        {--timezone=America/Sao_Paulo : Maintenance timezone}
        {--json : Print JSON}';

    protected $description = 'Manage Atlas Mac Agent power sessions, status and maintenance wake schedules.';

    public function handle(MacAgentService $agent): int
    {
        $action = (string) $this->argument('action');

        $result = match ($action) {
            'status', 'doctor' => $agent->status(refresh: true),
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
