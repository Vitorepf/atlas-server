<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCatalog;
use Illuminate\Console\Command;

/**
 * BRAIN CATALOG — dumps the portfolio path catalog as configured. Pure-read, useful for operator
 * inspection of the rotation surface without grepping config files. Pétreo.
 */
final class AtlasBrainCatalogCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:catalog {--intent= : filter by intent} {--kind= : filter by objective_kind} {--check : validate portfolio integrity (exit 1 on drift)} {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'Dump the portfolio path catalog (config(atlas.brain.paths)) with optional intent/kind filters.';

    public function handle(): int
    {
        $catalog = app(AtlasBrainPathCatalog::class);
        $intent = trim((string) ($this->option('intent') ?? ''));
        $kind = trim((string) ($this->option('kind') ?? ''));

        $entries = $intent !== '' ? $catalog->byIntent($intent) : $catalog->all();
        if ($kind !== '') {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => trim((string) ($e['objective_kind'] ?? '')) === $kind));
        }

        $payload = [
            'count' => count($entries),
            'filters' => [
                'intent' => $intent !== '' ? $intent : null,
                'kind' => $kind !== '' ? $kind : null,
            ],
            'paths' => array_map(static fn (array $e): array => [
                'id' => (string) ($e['id'] ?? ''),
                'intent' => (string) ($e['intent'] ?? ''),
                'objective_kind' => (string) ($e['objective_kind'] ?? ''),
                'executor_organ' => (string) ($e['executor_organ'] ?? ''),
                'lens' => (string) ($e['lens'] ?? ''),
            ], $entries),
        ];

        // INTEGRITY CHECK (opt-in): exit 1 when portfolio diverges from the canonical 7 / missing executors.
        $checkExitCode = self::SUCCESS;
        if ($this->option('check')) {
            $allEntries = $catalog->all();
            $canonical = ['frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique', 'compounding'];
            $present = array_filter(array_map(static fn (array $e): string => (string) ($e['id'] ?? ''), $allEntries));
            $missingCanonical = array_values(array_diff($canonical, $present));
            $missingExecutors = [];
            foreach ($allEntries as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if ($id !== '' && $catalog->executorOrganFor($id) === null) {
                    $missingExecutors[] = $id;
                }
            }
            $checkFailed = count($allEntries) !== 7 || $missingCanonical !== [] || $missingExecutors !== [];
            $payload['check'] = [
                'expected_count' => 7,
                'actual_count' => count($allEntries),
                'missing_canonical' => $missingCanonical,
                'missing_executors' => $missingExecutors,
                'ok' => ! $checkFailed,
            ];
            $checkExitCode = $checkFailed ? self::FAILURE : self::SUCCESS;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $checkExitCode;
    }
}
