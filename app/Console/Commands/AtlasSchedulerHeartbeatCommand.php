<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Illuminate\Console\Command;

/**
 * Records a heartbeat — invoked every minute by Laravel scheduler.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-scheduler-os.md
 */
class AtlasSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'atlas:scheduler:heartbeat
        {--actor=scheduler_tick : Actor identifier}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Record a scheduler heartbeat tick (Patamar 4 Cron OS probe).';

    public function handle(AtlasSchedulerHealthService $svc): int
    {
        $actor = (string) ($this->option('actor') ?: 'scheduler_tick');
        $beat = $svc->recordHeartbeat($actor);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($beat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Heartbeat</>', $beat['timestamp']);
        $this->components->twoColumnDetail('Hash', $beat['heartbeat_hash']);

        return self::SUCCESS;
    }
}
