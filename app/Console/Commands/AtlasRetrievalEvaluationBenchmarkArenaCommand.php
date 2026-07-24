<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasRetrievalEvaluationBenchmarkArenaCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:evaluate-retrieval
        {--risk=low : Risk level for the golden-set gate}
        {--json : Emit canonical JSON}';

    protected $description = 'Run AUCRI AREBA internal retrieval evaluation arena without external rivals.';

    public function handle(AtlasRetrievalEvaluationBenchmarkArenaService $service): int
    {
        $payload = $service->evaluate([
            'risk_level' => (string) $this->option('risk'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Retrieval Evaluation Arena', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Cases', (string) data_get($payload, 'summary.case_count', 0));
        $this->components->twoColumnDetail('Passed', (string) data_get($payload, 'summary.passed', 0));
        $this->components->twoColumnDetail('Recall', (string) data_get($payload, 'summary.metrics.required_source_recall', 0));
        $this->components->twoColumnDetail('Groundedness', (string) data_get($payload, 'summary.metrics.groundedness', 0));
        $this->components->twoColumnDetail('Context ROI', (string) data_get($payload, 'summary.metrics.context_roi', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
