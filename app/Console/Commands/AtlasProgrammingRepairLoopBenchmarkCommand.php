<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingRepairLoopBenchmarkService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProgrammingRepairLoopBenchmarkCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:repair-loop-benchmark
        {--json : Emit JSON output.}';

    protected $description = 'Run the governed Programming Repair Loop golden-set benchmark.';

    public function handle(ProgrammingRepairLoopBenchmarkService $benchmark): int
    {
        $report = $benchmark->run();

        if ($this->option('json')) {
            $this->line($this->encode($report));

            return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Programming repair loop benchmark: '.($report['status'] ?? 'unknown'));
        $this->line('Repair planning pass rate: '.data_get($report, 'metrics.repair_planning_pass_rate'));
        $this->line('Guard case pass rate: '.data_get($report, 'metrics.guard_case_pass_rate'));
        $this->line('Receipt integrity: '.(data_get($report, 'metrics.receipt_integrity_passed') ? 'passed' : 'failed'));
        $this->line('Failed cases: '.data_get($report, 'metrics.failed_case_count'));

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
