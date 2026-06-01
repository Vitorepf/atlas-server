<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasQualityBarAndMetricsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Projects the Atlas Self-Construction quality contract: the metrics catalog,
 * the ordered quality gates and the score-band ladder. Default invocation prints
 * the canonical contract; it is the runtime witness for the documented quality bar.
 *
 * @see docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
 */
class AtlasQualityBarAndMetricsCommand extends Command
{
    protected $signature = 'atlas:aaeos:quality-bar-and-metrics {--json}';

    protected $description = 'Project the Atlas self-construction quality bar: metrics catalog, quality gates and score-band ladder.';

    public function handle(AtlasQualityBarAndMetricsService $service): int
    {
        try {
            $result = $service->contract();

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('metric dimensions', (string) $result['metric_dimension_count']);
            $this->components->twoColumnDetail('quality gates', (string) $result['quality_gate_count']);
            $this->components->twoColumnDetail('strict score ceiling', (string) $result['strict_ceiling']);

            foreach ($result['score_bands'] as $band) {
                $this->components->twoColumnDetail("band >= {$band['min']}", $band['meaning']);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => 'atlas.aaeos.quality_bar_and_metrics.v1',
                'error' => true,
                'message' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
