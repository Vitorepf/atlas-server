<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogRunner;
use Illuminate\Console\Command;

final class AtlasWatchdogRunCommand extends Command
{
    protected $signature = 'atlas:watchdog:run
        {--json : Emit canonical JSON}';

    protected $description = 'ACOS WDG-01 — run the unified watchdog check registry.';

    public function handle(AtlasWatchdogRunner $runner): int
    {
        $payload = $runner->run();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '?'));
            $this->components->twoColumnDetail('checks', (string) data_get($payload, 'counts.total', 0));
            $this->components->twoColumnDetail('alerts', (string) count((array) ($payload['alerts'] ?? [])));
        }

        return ($payload['alert'] ?? false) ? self::FAILURE : self::SUCCESS;
    }
}
