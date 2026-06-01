<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasExperimentationEngineService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercises the Atlas Experimentation Engine runtime: runs the documented
 * experiment loop over a sample experiment and prints the resolved step-8
 * decision (continue / pivot / escalate / stop), the quality-gate readiness,
 * and the metric-choice guard.
 *
 * @see docs/engineering-knowledge-base/atlas-experimentation-engine.md
 */
class AtlasExperimentationEngineCommand extends Command
{
    protected $signature = 'atlas:aaeos:experimentation-engine {--json}';

    protected $description = 'Run the Atlas Experimentation Engine decision over a sample experiment.';

    public function handle(AtlasExperimentationEngineService $engine): int
    {
        try {
            $experiment = [
                'hypothesis' => 'A shorter checkout raises completed-purchase rate.',
                'primary_metric' => [
                    'name' => 'completed_purchase_rate',
                    'target' => 0.12,
                    'observed' => 0.15,
                    'business_metric' => 'completed_purchase_rate',
                    'is_vanity' => false,
                ],
                'sample_size' => 1200,
                'required_sample' => 800,
            ];

            $result = $engine->runExperiment($experiment, ['approved' => true]);

            $payload = $result + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('ready to report', $result['readiness']['ready_to_report'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('is validation', $result['readiness']['is_validation'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('metric allowed', $result['metric_choice']['allowed'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('decision', $result['decision']);
            $this->components->twoColumnDetail('human review required', $result['human_review_required'] ? 'yes' : 'no');
            $this->info($result['reason']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasExperimentationEngineService::SCHEMA_VERSION,
                'ok' => false,
                'error' => $e->getMessage(),
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
