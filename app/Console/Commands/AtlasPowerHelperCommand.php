<?php

namespace App\Console\Commands;

use App\Models\AtlasMaintenanceWindow;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Console\Command;

class AtlasPowerHelperCommand extends Command
{
    protected $signature = 'atlas:power-helper
        {--once : Run one pass}
        {--sleep=300 : Seconds between passes when not --once}';

    protected $description = 'Privileged helper for macOS power operations that require root, such as pmset wake scheduling.';

    public function handle(MacAgentService $agent): int
    {
        $once = (bool) $this->option('once');
        $sleep = max(60, (int) $this->option('sleep'));

        do {
            $scheduled = 0;
            AtlasMaintenanceWindow::query()
                ->where('host_key', MacAgentService::HOST_KEY)
                ->where('enabled', true)
                ->orderBy('wake_time')
                ->get()
                ->each(function (AtlasMaintenanceWindow $window) use ($agent, &$scheduled): void {
                    if ($agent->scheduleWakeForWindow($window)) {
                        $scheduled++;
                    }
                });
            $runningAsRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;
            $agent->recordPowerHelperCheck($runningAsRoot, $scheduled);

            $this->line(json_encode([
                'status' => 'ok',
                'running_as_root' => $runningAsRoot,
                'scheduled_windows' => $scheduled,
                'checked_at' => now()->toJSON(),
            ], JSON_UNESCAPED_SLASHES));

            if (! $once) {
                sleep($sleep);
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
