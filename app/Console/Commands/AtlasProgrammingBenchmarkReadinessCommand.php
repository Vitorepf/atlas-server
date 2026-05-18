<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessAuthorizationException;
use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessHarness;
use Illuminate\Console\Command;

class AtlasProgrammingBenchmarkReadinessCommand extends Command
{
    protected $signature = 'atlas:programming:benchmark-readiness
        {action=readiness : readiness|manifest|suite|validate}
        {--json : print JSON only}';

    protected $description = 'Atlas Programming Benchmark Readiness Harness — prepare suite/manifest/rubric only. NEVER executes benchmark, NEVER calls rival providers. `run` is intentionally not exposed as a CLI action.';

    public function handle(BenchmarkReadinessHarness $harness): int
    {
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'readiness' => $harness->readinessJson(),
            'manifest' => $harness->manifestJson(),
            'suite' => $harness->suite(),
            'validate' => $harness->validate(),
            'run' => $this->refuseRunFromCli($harness),
            default => null,
        };

        if ($payload === null) {
            $this->error('invalid action ['.$action.']; supported: readiness, manifest, suite, validate');

            return Command::FAILURE;
        }

        $this->line(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}');

        // validate sub-action surfaces non-zero exit when any invariant fails.
        if ($action === 'validate') {
            $status = (string) ($payload['validation']['status'] ?? 'unknown');
            if ($status !== 'passed') {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function refuseRunFromCli(BenchmarkReadinessHarness $harness): ?array
    {
        try {
            $harness->run();
        } catch (BenchmarkReadinessAuthorizationException $exception) {
            $this->error($exception->getMessage());
        }

        // CLI never returns a payload for `run` — the action is intentionally
        // not wired. Returning null surfaces the standard "invalid action" path.
        return null;
    }
}
