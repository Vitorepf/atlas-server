<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingTestImpactBenchmarkService;
use Illuminate\Console\Command;

class AtlasProgrammingTestImpactBenchmarkCommand extends Command
{
    protected $signature = 'atlas:programming:test-impact-benchmark
        {--json : Emit JSON output.}';

    protected $description = 'Run the governed Programming Test Impact Analysis golden-set benchmark.';

    public function handle(ProgrammingTestImpactBenchmarkService $benchmark): int
    {
        $report = $benchmark->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Programming test impact benchmark: '.($report['status'] ?? 'unknown'));
        $this->line('Recall: '.data_get($report, 'metrics.recall'));
        $this->line('Precision: '.data_get($report, 'metrics.precision'));
        $this->line('Failed cases: '.data_get($report, 'metrics.failed_case_count'));

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
