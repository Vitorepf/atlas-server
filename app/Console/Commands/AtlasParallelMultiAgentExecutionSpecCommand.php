<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasParallelMultiAgentExecutionSpecService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Parallel Multi-Agent Execution Spec CLI.
 *
 *   php artisan atlas:aaeos:parallel-multi-agent-execution-spec [--run=/tmp/run.json] [--json]
 *
 * Pure, deterministic gate over the FIVE durable-parallelism invariants. Reads
 * an optional run descriptor (reservations, worktrees, collision report, ring,
 * review) from JSON and emits the assessment verdict (run_parallel | block).
 * With no --run it evaluates a safe demo run. NEVER mutates git or files.
 *
 * @see docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
 */
final class AtlasParallelMultiAgentExecutionSpecCommand extends Command
{
    protected $signature = 'atlas:aaeos:parallel-multi-agent-execution-spec
        {--run= : path to a JSON file with the parallel run descriptor}
        {--json : machine-readable JSON output}';

    protected $description = 'Atlas AAEOS · assess a parallel multi-agent run against the five durable invariants (run_parallel|block).';

    public function handle(AtlasParallelMultiAgentExecutionSpecService $service): int
    {
        try {
            $run = $service->demoRun();

            $runOpt = $this->option('run');
            if (is_string($runOpt) && trim($runOpt) !== '') {
                $path = trim($runOpt);
                if (! is_file($path)) {
                    return $this->failEnvelope("run file not found: {$path}");
                }
                $raw = (string) file_get_contents($path);
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    return $this->failEnvelope("run file is not a JSON object: {$path}");
                }
                $run = $decoded;
            }

            $result = $service->assess($run);

            if ((bool) $this->option('json') || ! is_string($runOpt)) {
                $this->line((string) json_encode(
                    ['ok' => true, 'result' => $result],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return self::SUCCESS;
            }

            $this->info('Atlas Parallel Multi-Agent Execution Spec');
            $this->line('  decision: '.((string) $result['decision']));
            $this->line('  parallel_safe: '.($result['parallel_safe'] ? 'true' : 'false'));
            $this->line('  invariants: '.((int) $result['summary']['invariants_satisfied']).'/'.((int) $result['summary']['invariants_total']));
            if ($result['missing_invariants'] !== []) {
                $this->line('  missing: '.implode(', ', $result['missing_invariants']));
            }
            $this->line('  blocking_count: '.((int) $result['blocking_count']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failEnvelope($e->getMessage());
        }
    }

    private function failEnvelope(string $message): int
    {
        $this->line((string) json_encode(
            ['ok' => false, 'error' => $message],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::FAILURE;
    }
}
