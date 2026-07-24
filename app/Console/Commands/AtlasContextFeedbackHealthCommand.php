<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextFeedbackHealthCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:feedback-health
        {--json : Emit canonical JSON}';

    protected $description = 'COM-10 — read-only context feedback signal health watchdog.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $health->contextFeedbackHealthReport();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('total_event_count', (string) data_get($payload, 'window.total_event_count', 0));
            $this->components->twoColumnDetail('measured_count', (string) data_get($payload, 'window.measured_count', 0));
            $this->components->twoColumnDetail('synthetic_share', (string) data_get($payload, 'window.synthetic_share', 0));
        }

        return ($payload['alert'] ?? false) === true ? self::FAILURE : self::SUCCESS;
    }
}
