<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasEngineeringEnforceReadinessCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:enforce-readiness
        {--json : Emit canonical JSON}';

    protected $description = 'ENG-11 — read-only readiness verdict for engineering enforcement flips.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $health->engineeringEnforceReadinessReport();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
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
