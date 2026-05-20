<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\BenchmarkReadiness\AtlasPreBenchmarkReadinessService;
use Illuminate\Console\Command;

final class AtlasProgrammingPreBenchmarkReadinessCommand extends Command
{
    protected $signature = 'atlas:programming:pre-benchmark-readiness
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'Final read-only pre-benchmark readiness gate. Does not run providers, rivals or benchmarks.';

    public function handle(AtlasPreBenchmarkReadinessService $service): int
    {
        $payload = $service->report();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas Programming Pre-Benchmark Readiness</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->line('Readiness hash: <comment>'.($payload['readiness_hash'] ?? 'missing').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== AtlasPreBenchmarkReadinessService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
