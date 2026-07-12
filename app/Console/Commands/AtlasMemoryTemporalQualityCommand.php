<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Memory\AtlasMemoryTemporalQualityService;
use Illuminate\Console\Command;

final class AtlasMemoryTemporalQualityCommand extends Command
{
    protected $signature = 'atlas:memory:temporal-quality
        {--freeze-payload : Print the MAXH-01 measure freeze payload instead of the live report}
        {--json : Print machine-readable JSON}';

    protected $description = 'MAXH-01 — read-only temporal truth v2 quality meter for Atlas Memory.';

    public function handle(AtlasMemoryTemporalQualityService $quality): int
    {
        $payload = (bool) $this->option('freeze-payload')
            ? ['freeze_payload' => AtlasMemoryTemporalQualityService::freezePayload()]
            : ['memory_temporal_quality' => $quality->report()];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        if (isset($payload['freeze_payload'])) {
            $freeze = $payload['freeze_payload'];
            $this->components->twoColumnDetail('Measure', (string) ($freeze['measure_id'] ?? 'unknown'));
            $this->components->twoColumnDetail('Formula', (string) ($freeze['formula_version'] ?? 'unknown'));

            return self::SUCCESS;
        }

        $report = $payload['memory_temporal_quality'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Memory Temporal Quality</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Reason', (string) ($report['reason'] ?? '-'));
        foreach ((array) ($report['metrics'] ?? []) as $name => $metric) {
            if (! is_array($metric)) {
                continue;
            }
            $this->components->twoColumnDetail(
                (string) $name,
                (string) ($metric['num'] ?? 0).'/'.(string) ($metric['den'] ?? 0),
            );
        }

        return self::SUCCESS;
    }
}
