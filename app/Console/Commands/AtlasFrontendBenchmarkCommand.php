<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendBenchmarkRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendBenchmarkCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:benchmark
        {--rival-evidence= : Directory containing external rival replay evidence manifests}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Run the Atlas Frontend competitive benchmark matrix.';

    public function handle(AtlasFrontendBenchmarkRuntimeService $benchmark): int
    {
        $payload = $benchmark->run((string) ($this->option('rival-evidence') ?? ''));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $atlas = $payload['totals']['atlas_frontend'] ?? ['score' => 0, 'max' => 0, 'percent' => 0];
            $this->line(sprintf('Atlas Frontend Benchmark: %s/%s (%s%%)', $atlas['score'], $atlas['max'], $atlas['percent']));
        }

        return self::SUCCESS;
    }
}
