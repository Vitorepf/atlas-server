<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasCompactionSoakWatchCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:compaction:soak-watch
        {--json : Emit canonical JSON}';

    protected $description = 'CPT-09 — read-only compaction soak readiness watchdog.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $health->compactionSoakWatchReport();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('ready_to_enforce', YesNo::trueFalse((bool) ($payload['ready_to_enforce'] ?? false)));
            $this->components->twoColumnDetail('compaction_count', (string) data_get($payload, 'window.compaction_count', 0));
        }

        return ($payload['ready_to_enforce'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
