<?php

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI surface for the Atlas Antifragility Composition Metric — the doc describes
 * the runtime but the existing service had no command. This thin command runs
 * the composition measurement so the runtime is operator-runnable.
 *
 * @see docs/engineering-knowledge-base/atlas-antifragility-composition-metric.md
 */
class AtlasAntifragilityMetricCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:compounding:antifragility-metric {--json}';

    protected $description = 'Measure the Atlas antifragility composition metric.';

    public function handle(AtlasAntifragilityCompositionMetricService $metric): int
    {
        try {
            $result = $metric->measure();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->jsonLine($result);

        return self::SUCCESS;
    }
}
