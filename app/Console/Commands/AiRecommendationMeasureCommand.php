<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\Engine\RecommendationMeasurementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AiRecommendationMeasureCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:recommendations:measure
        {--now= : Override measurement clock for backfills/tests}
        {--limit=100 : Maximum applied recommendations to measure}
        {--json : Print machine-readable JSON}';

    protected $description = 'Measure applied Atlas performance recommendations and close effective ones.';

    public function handle(RecommendationMeasurementService $measurements): int
    {
        $now = $this->clock();
        $limit = max(1, min(500, (int) $this->option('limit')));
        $result = $measurements->measureDue($now, $limit);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'checked=%d measured=%d deferred=%d self_healed=%d',
            (int) ($result['checked'] ?? 0),
            (int) ($result['measured'] ?? 0),
            (int) ($result['deferred'] ?? 0),
            (int) ($result['self_healed'] ?? 0),
        ));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function clock(): CarbonImmutable
    {
        $value = $this->option('now');
        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse(trim($value));
        }

        return CarbonImmutable::now();
    }
}
