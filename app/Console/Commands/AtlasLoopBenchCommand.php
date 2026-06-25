<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseBenchRunner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseCoverageReporter;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface for the hard-case benchmark dataset:
 *   list     — print the registry (optionally filtered by --source).
 *   run      — invoke {@see AtlasLoopHardCaseBenchRunner}::run($caseId) (or runAll when --case omitted).
 *   coverage — print {@see AtlasLoopHardCaseCoverageReporter}::report() including capabilities_with_zero_cases.
 *
 * Anti-Goodhart: thin pass-through — zero business logic in the command body. All work lives in the
 * underlying services (registry / runner / reporter). The command never aggregates a single coverage number.
 */
final class AtlasLoopBenchCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:bench {action : list|run|coverage} {--case= : specific case_id for run} {--source= : filter for list} {--json}';

    /** @var string */
    protected $description = 'Hard-case bench: list / run / coverage.';

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));

        return match ($action) {
            'list' => $this->listCases(),
            'run' => $this->runCases(),
            'coverage' => $this->coverage(),
            default => $this->usage($action),
        };
    }

    private function listCases(): int
    {
        $registry = $this->app()->make(AtlasLoopHardCaseDatasetRegistry::class);
        $source = trim((string) $this->option('source'));
        $cases = $source === '' ? $registry->all() : $registry->bySource($source);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($cases as $c) {
                $this->line(sprintf('%s | %s | %s', (string) ($c['case_id'] ?? ''), (string) ($c['source'] ?? ''), (string) ($c['scope_root'] ?? '')));
            }
        }

        return self::SUCCESS;
    }

    private function runCases(): int
    {
        try {
            $runner = $this->app()->make(AtlasLoopHardCaseBenchRunner::class);
        } catch (Throwable $e) {
            $this->line((string) json_encode(['status' => 'skipped', 'reason' => 'bench_runner_unwired', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $case = trim((string) $this->option('case'));
        $results = $case === '' ? $runner->runAll() : [$runner->run($case)];
        $payload = array_map(static fn ($r): array => $r->toArray(), $results);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($payload as $row) {
                $this->line(sprintf(
                    '%s | %s | observed=%s',
                    (string) ($row['case_id'] ?? ''),
                    (string) ($row['outcome'] ?? ''),
                    (string) ($row['observed_failure_signature'] ?? '(none)'),
                ));
            }
        }

        return self::SUCCESS;
    }

    private function coverage(): int
    {
        try {
            $reporter = $this->app()->make(AtlasLoopHardCaseCoverageReporter::class);
        } catch (Throwable $e) {
            $this->line((string) json_encode(['status' => 'skipped', 'reason' => 'coverage_reporter_unwired', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $report = $reporter->report();
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line($reporter->renderText());
        }

        return self::SUCCESS;
    }

    private function usage(string $action): int
    {
        $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'unknown action: '.$action, 'allowed' => ['list', 'run', 'coverage']], JSON_UNESCAPED_SLASHES));

        return self::INVALID;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
