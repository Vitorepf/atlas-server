<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexOrphanAgeReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexSymbolAgeReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexTemporalAxisQueryService;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Console\Command;

/**
 * FACTS-only operator surface over the Cortex temporal services. NEVER fabricates rows; exit
 * codes reflect REALITY: 0 = at least one FACT row, 2 = zero rows (genuine empty), 1 = invalid
 * input. The CLI is a thin pass-through: --json emits the underlying service result verbatim.
 */
final class AtlasLoopCortexTemporalCommand extends Command
{
    public const VALID_PREDICATES = ['touched-within', 'untouched-since', 'orphans-older-than', 'added-between'];

    protected $signature = 'atlas:loop:cortex:temporal {action : age|orphan-age|query|history} {--fqcn=*} {--predicate=} {--days=} {--from=} {--to=} {--json}';

    protected $description = 'Operator surface for the Cortex temporal axis (age | orphan-age | query | history).';

    public function __construct(
        private readonly AtlasCortexSymbolAgeReporter $ageReporter,
        private readonly AtlasCortexOrphanAgeReporter $orphanReporter,
        private readonly AtlasCortexTemporalAxisQueryService $query,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'age' => $this->doAge(),
            'orphan-age' => $this->doOrphanAge(),
            'query' => $this->doQuery(),
            'history' => $this->doHistory(),
            default => $this->emit([
                'error' => 'unknown_action',
                'message' => 'unknown action; expected one of: age, orphan-age, query, history',
            ], 1),
        };
    }

    private function doAge(): int
    {
        $fqcns = $this->fqcnList();
        if ($fqcns === []) {
            return $this->emit(['error' => 'fqcn_required'], 1);
        }
        $rows = $this->ageReporter->report($fqcns);

        return $this->emit($rows, $rows === [] ? 2 : 0);
    }

    private function doOrphanAge(): int
    {
        $fqcns = $this->fqcnList();
        if ($fqcns === []) {
            return $this->emit(['error' => 'fqcn_required'], 1);
        }
        $rows = $this->orphanReporter->report($fqcns);

        return $this->emit($rows, $rows === [] ? 2 : 0);
    }

    private function doQuery(): int
    {
        $predicate = (string) $this->option('predicate');
        if (! in_array($predicate, self::VALID_PREDICATES, true)) {
            return $this->emit([
                'error' => 'invalid_predicate',
                'message' => 'predicate must be one of: '.implode(', ', self::VALID_PREDICATES),
            ], 1);
        }
        $fqcns = $this->fqcnList();

        try {
            $rows = match ($predicate) {
                'touched-within' => $this->query->symbolsTouchedWithin(
                    new DateInterval('P'.max(1, (int) $this->option('days')).'D'),
                    $fqcns,
                ),
                'untouched-since' => $this->query->symbolsUntouchedSince(
                    CarbonImmutable::parse((string) $this->option('from')),
                    $fqcns,
                ),
                'orphans-older-than' => $this->query->orphansOlderThan(
                    max(0, (int) $this->option('days')),
                    $fqcns,
                ),
                'added-between' => $this->query->symbolsAddedBetween(
                    CarbonImmutable::parse((string) $this->option('from')),
                    CarbonImmutable::parse((string) $this->option('to')),
                    $fqcns,
                ),
            };
        } catch (\Throwable $e) {
            return $this->emit(['error' => 'invalid_query', 'message' => $e->getMessage()], 1);
        }

        return $this->emit($rows, $rows === [] ? 2 : 0);
    }

    private function doHistory(): int
    {
        $fqcns = $this->fqcnList();
        if ($fqcns === []) {
            return $this->emit(['error' => 'fqcn_required'], 1);
        }
        $windowDays = max(1, (int) ($this->option('days') ?? 90));
        $out = [];
        foreach ($fqcns as $fqcn) {
            $out[$fqcn] = $this->query->modificationHistogramByDay($fqcn, $windowDays);
        }

        return $this->emit($out, $out === [] ? 2 : 0);
    }

    /**
     * @return list<string>
     */
    private function fqcnList(): array
    {
        $raw = (array) $this->option('fqcn');

        return array_values(array_filter(array_map('strval', $raw), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  array<string|int,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $exit;
    }
}
