<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendBenchmarkRuntimeService;
use Illuminate\Console\Command;

class AtlasFrontendBenchmarkCommand extends Command
{
    protected $signature = 'atlas:frontend:benchmark
        {--json : Emit canonical JSON payload}';

    protected $description = 'Run the Atlas Frontend competitive benchmark matrix.';

    public function handle(AtlasFrontendBenchmarkRuntimeService $benchmark): int
    {
        $payload = $benchmark->run();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $atlas = $payload['totals']['atlas_frontend'] ?? ['score' => 0, 'max' => 0, 'percent' => 0];
            $this->line(sprintf('Atlas Frontend Benchmark: %s/%s (%s%%)', $atlas['score'], $atlas['max'], $atlas['percent']));
        }

        return self::SUCCESS;
    }
}
