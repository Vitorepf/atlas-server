<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\DevRunControlSnapshotService;
use Illuminate\Console\Command;

/**
 * Read-only control snapshot for an Atlas Dev run.
 *
 * Usage:
 *   php artisan atlas:dev:run-snapshot --run=<runId> [--json]
 *   php artisan atlas:dev:run-snapshot              (lists recent runs)
 */
final class AtlasDevRunSnapshotCommand extends Command
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:dev:run-snapshot {--run= : Dev run ID to snapshot} {--json : Output raw JSON}';

    protected $description = 'Read-only control snapshot for a Dev run.';

    public function handle(DevRunControlSnapshotService $snapshot): int
    {
        $runId = (string) $this->option('run');

        $payload = $snapshot->snapshot($runId);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $line = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
                $this->line($k.': '.$line);
            }
        }

        return self::EXIT_OK;
    }
}
