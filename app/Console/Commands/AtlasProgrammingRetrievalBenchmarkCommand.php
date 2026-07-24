<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingRetrievalBenchmarkService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProgrammingRetrievalBenchmarkCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:retrieval-benchmark
        {--workspace= : Workspace to benchmark. Defaults to the Laravel base path.}
        {--refresh : Recompute benchmark instead of using process-memory cache.}
        {--json : Emit JSON output.}';

    protected $description = 'Run the governed Programming Agentic RAG retrieval golden-set benchmark.';

    public function handle(ProgrammingRetrievalBenchmarkService $benchmark): int
    {
        $workspace = is_string($this->option('workspace')) && $this->option('workspace') !== ''
            ? (string) $this->option('workspace')
            : base_path();

        $report = $benchmark->run($workspace, (bool) $this->option('refresh'));

        if ($this->option('json')) {
            $this->line($this->encode($report));

            return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Programming retrieval benchmark: '.($report['status'] ?? 'unknown'));
        $this->line('Recall@k: '.data_get($report, 'metrics.recall_at_k'));
        $this->line('Precision@k: '.data_get($report, 'metrics.precision_at_k'));
        $this->line('Failed cases: '.data_get($report, 'metrics.failed_case_count'));
        $this->line('Runtime cache: '.(data_get($report, 'runtime_cache.hit') ? 'hit' : 'fresh'));

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
