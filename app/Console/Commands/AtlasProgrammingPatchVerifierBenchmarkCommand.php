<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingPatchVerifierBenchmarkService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProgrammingPatchVerifierBenchmarkCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:patch-verifier-benchmark
        {--json : Emit JSON output.}';

    protected $description = 'Run the governed Programming Patch Verifier grounded-patch golden-set benchmark.';

    public function handle(ProgrammingPatchVerifierBenchmarkService $benchmark): int
    {
        $report = $benchmark->run();

        if ($this->option('json')) {
            $this->line($this->encode($report));

            return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Programming patch verifier benchmark: '.($report['status'] ?? 'unknown'));
        $this->line('Grounded patch rate: '.data_get($report, 'metrics.grounded_patch_rate'));
        $this->line('Failed cases: '.data_get($report, 'metrics.failed_case_count'));

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
