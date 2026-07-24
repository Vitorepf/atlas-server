<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasEngineeringEnforceReadinessCommand extends Command
{
    protected $signature = 'atlas:engineering:enforce-readiness
        {--json : Emit canonical JSON}';

    protected $description = 'ENG-11 — read-only readiness verdict for engineering enforcement flips.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $health->engineeringEnforceReadinessReport();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('ready_to_enforce', YesNo::trueFalse((bool) ($payload['ready_to_enforce'] ?? false)));
            foreach ((array) ($payload['flips'] ?? []) as $id => $flip) {
                $this->components->twoColumnDetail((string) $id, ((bool) ($flip['ready'] ?? false)) ? 'ready' : 'not_ready');
            }
        }

        return ($payload['ready_to_enforce'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
