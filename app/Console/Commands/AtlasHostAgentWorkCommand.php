<?php

namespace App\Console\Commands;

use App\Models\AtlasMaintenanceWindow;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Console\Command;

class AtlasHostAgentWorkCommand extends Command
{
    protected $signature = 'atlas:host-agent:work
        {--once : Run one pass and exit}
        {--sleep=30 : Seconds between passes}
        {--schedule-every=3600 : Seconds between maintenance wake schedule refreshes}';

    protected $description = 'Run the Atlas Mac Agent loop for heartbeats, session expiry and maintenance wake scheduling.';

    public function handle(MacAgentService $agent): int
    {
        $once = (bool) $this->option('once');
        $sleep = max(5, (int) $this->option('sleep'));
        $scheduleEvery = max(300, (int) $this->option('schedule-every'));
        $lastScheduleAt = null;

        do {
            $cycle = $agent->runMaintenanceCycle();

            if ($lastScheduleAt === null || $lastScheduleAt->diffInSeconds(now(), true) >= $scheduleEvery) {
                $this->refreshSchedules($agent);
                $lastScheduleAt = now();
            }

            $this->line(json_encode([
                'status' => 'ok',
                'expired_sessions' => $cycle['expired']['count'],
                'restarted_caffeinate' => $cycle['reconciled']['restarted'] ?? 0,
                'failed_caffeinate_restarts' => $cycle['reconciled']['failed'] ?? 0,
                'started_sessions' => count($cycle['started']),
                'sleep_after_idle' => $cycle['sleep_after_idle'],
                'checked_at' => now()->toJSON(),
            ], JSON_UNESCAPED_SLASHES));

            if (! $once) {
                sleep($sleep);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function refreshSchedules(MacAgentService $agent): void
    {
        AtlasMaintenanceWindow::query()
            ->where('host_key', MacAgentService::HOST_KEY)
            ->where('enabled', true)
            ->orderBy('wake_time')
            ->each(fn (AtlasMaintenanceWindow $window): bool => $agent->scheduleWakeForWindow($window) || true);
    }
}
