<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Illuminate\Console\Command;

/**
 * Reports scheduler health — silent_alarm if last heartbeat too old.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-scheduler-os.md
 */
class AtlasSchedulerStatusCommand extends Command
{
    protected $signature = 'atlas:scheduler:status
        {--threshold=300 : Silent alarm threshold in seconds}
        {--strict : Exit 3 if silent_alarm=true}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show Atlas scheduler health (silent_alarm probe).';

    public function handle(AtlasSchedulerHealthService $svc): int
    {
        $threshold = (int) $this->option('threshold');
        $status = $svc->status($threshold);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Scheduler</>', $status['silent_alarm'] ? '<fg=red>silent</>' : '<fg=green>alive</>');
            $this->components->twoColumnDetail('Last heartbeat', (string) ($status['last_heartbeat_at'] ?? 'never'));
            $this->components->twoColumnDetail('Age seconds', (string) ($status['age_seconds'] ?? 'n/a'));
            $this->components->twoColumnDetail('Threshold', (string) $status['silent_threshold_seconds']);
            $this->components->twoColumnDetail('Heartbeats total', (string) $status['heartbeat_count']);
        }

        if ((bool) $this->option('strict') && ($status['silent_alarm'] ?? true)) {
            return 3;
        }

        return self::SUCCESS;
    }
}
