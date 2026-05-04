<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AtlasEngineeringBenchmarkFairCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:claude-fair
        {action=run : prepare, run, run-atlas, run-claude-code, report or replay}
        {run? : Benchmark run id for replay}
        {--suite=atlas-core-smoke : Suite slug or id}
        {--workspace= : Workspace path for benchmark execution}
        {--case=* : Restrict execution to one or more case codes}
        {--tag=* : Restrict execution to cases carrying these tags}
        {--limit= : Maximum number of cases or report runs}
        {--tier= : Restrict execution to a corpus tier}
        {--domain= : Restrict execution to a corpus domain}
        {--risk= : Restrict execution to a risk profile}
        {--curation-status= : Restrict execution to a curation status}
        {--test-command= : Explicit deterministic validation command}
        {--claude-code-baseline-workspace= : Separate workspace for claude-code baseline run}
        {--claude-code-baseline-binary= : Claude Code CLI binary override}
        {--claude-code-baseline-timeout=900 : Seconds to wait for Claude Code baseline run}
        {--claude-code-baseline-validation-timeout=300 : Seconds to wait for baseline deterministic validation}
        {--max-attempts=3 : Maximum Atlas repair attempts}
        {--permission=auto : auto, read, write or danger}
        {--sandbox=workspace : workspace, worktree or docker}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--gate-profile=strict : Release gate profile: release, smoke, strict, advisory or off}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-auto-test : Disable auto-test for run and run-atlas}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the official opt-in Fair Claude benchmark workflow against Claude Code CLI.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $action = $this->normalizeAction((string) $this->argument('action'));

        return match ($action) {
            'prepare' => $this->callForwarded('atlas:engineering:benchmark:seed', $this->prepareArgs()),
            'run' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('run')),
            'run-atlas' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('off')),
            'run-claude-code' => $this->callForwarded('atlas:engineering:benchmark', $this->runArgs('run', noProvider: true)),
            'report' => $this->report($benchmarks),
            'replay' => $this->replay(),
            default => $this->unknownAction($action),
        };
    }

    private function report(EngineeringBenchmarkService $benchmarks): int
    {
        $suiteRef = $this->suite();
        $suite = AtlasEngineeringBenchmarkSuite::query()
            ->where('id', $suiteRef)
            ->orWhere('slug', $suiteRef)
            ->first();
        if (! $suite) {
            $this->error("Benchmark suite nao encontrada: {$suiteRef}");

            return self::FAILURE;
        }

        $payload = $benchmarks->fairClaudeReportPayload($suite, [
            'limit' => $this->intOption('limit') ?: 20,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        return $this->callForwarded('atlas:engineering:benchmark:report', $this->reportArgs());
    }

    private function callForwarded(string $command, array $args): int
    {
        return Artisan::call($command, $args, $this->output);
    }

    /**
     * @return array<string,mixed>
     */
    private function prepareArgs(): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--workspace' => $this->stringOption('workspace'),
            '--tier' => $this->stringOption('tier') ?: 'release',
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->stringOption('curation-status') ?: 'curated',
            '--tag' => (array) $this->option('tag'),
            '--refresh-manifest' => true,
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @return array<string,mixed>
     */
    private function runArgs(string $baselineMode, bool $noProvider = false): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--workspace' => $this->stringOption('workspace'),
            '--case' => (array) $this->option('case'),
            '--tag' => (array) $this->option('tag'),
            '--limit' => $this->intOption('limit'),
            '--tier' => $this->stringOption('tier'),
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->stringOption('curation-status'),
            '--model' => 'opus',
            '--model-policy' => 'fixed',
            '--claude-only' => ! $noProvider,
            '--single-provider' => true,
            '--no-decide' => true,
            '--fallback-disabled' => true,
            '--claude-code-baseline' => $baselineMode,
            '--claude-code-baseline-model' => 'opus',
            '--claude-code-baseline-binary' => $this->stringOption('claude-code-baseline-binary'),
            '--claude-code-baseline-workspace' => $this->stringOption('claude-code-baseline-workspace'),
            '--claude-code-baseline-timeout' => $this->intOption('claude-code-baseline-timeout') ?: 900,
            '--claude-code-baseline-validation-timeout' => $this->intOption('claude-code-baseline-validation-timeout') ?: 300,
            '--permission' => $this->stringOption('permission') ?: 'auto',
            '--sandbox' => $this->stringOption('sandbox') ?: 'workspace',
            '--provider-runtime' => $this->stringOption('provider-runtime') ?: 'host',
            '--max-attempts' => $this->intOption('max-attempts') ?: 3,
            '--test-command' => $this->stringOption('test-command'),
            '--complete' => ! $noProvider,
            '--auto-test' => ! (bool) $this->option('no-auto-test'),
            '--no-provider' => $noProvider,
            '--keep-workspace' => (bool) $this->option('keep-workspace'),
            '--gate-profile' => $this->stringOption('gate-profile') ?: 'strict',
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '' && $value !== false);
    }

    /**
     * @return array<string,mixed>
     */
    private function reportArgs(): array
    {
        return array_filter([
            '--suite' => $this->suite(),
            '--limit' => $this->intOption('limit') ?: 20,
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false);
    }

    private function replay(): int
    {
        $run = $this->argument('run');
        if (! is_string($run) || trim($run) === '') {
            $this->error('replay exige o id do benchmark run.');

            return self::FAILURE;
        }

        return $this->callForwarded('atlas:engineering:benchmark:replay-manifest', [
            'run' => trim($run),
            '--json' => (bool) $this->option('json'),
        ]);
    }

    private function unknownAction(string $action): int
    {
        $this->error("Acao Fair Claude desconhecida: {$action}");
        $this->line('Use: prepare, run, run-atlas, run-claude-code, report ou replay.');

        return self::FAILURE;
    }

    private function suite(): string
    {
        return $this->stringOption('suite') ?: 'atlas-core-smoke';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeAction(string $action): string
    {
        return match (str_replace('_', '-', strtolower(trim($action)))) {
            'prep', 'prepare-suite' => 'prepare',
            'atlas', 'run-atlas-arm' => 'run-atlas',
            'claude-code', 'baseline', 'run-baseline', 'run-claude-code-baseline' => 'run-claude-code',
            'scorecard', 'summary' => 'report',
            'manifest', 'replay-manifest' => 'replay',
            default => str_replace('_', '-', strtolower(trim($action))),
        };
    }
}
