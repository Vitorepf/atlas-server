<?php

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI surface for the Atlas Antifragility Composition Metric — the doc describes
 * the runtime but the existing service had no command. This thin command runs
 * the composition measurement so the runtime is operator-runnable.
 *
 * @see docs/engineering-knowledge-base/atlas-antifragility-composition-metric.md
 */
class AtlasAntifragilityMetricCommand extends Command
{
    protected $signature = 'atlas:compounding:antifragility-metric {--json}';

    protected $description = 'Measure the Atlas antifragility composition metric.';

    public function handle(AtlasAntifragilityCompositionMetricService $metric): int
    {
        try {
            $result = $metric->measure();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
