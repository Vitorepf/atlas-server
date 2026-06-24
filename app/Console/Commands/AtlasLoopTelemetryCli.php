<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactExporter;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactStreamEmitter;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactWindowAggregator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class AtlasLoopTelemetryCli extends Command
{
    protected $signature = 'atlas:loop:telemetry {action : tail|aggregate|export} {--minutes=15} {--path=} {--json}';

    protected $description = 'Read-only operator surface over the canonical Loop telemetry FACT stream. No score, no rank.';

    /** @var \Closure(): DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly ?AtlasLoopTelemetryFactStreamEmitter $emitter = null,
        private readonly ?AtlasLoopTelemetryFactWindowAggregator $aggregator = null,
        private readonly ?AtlasLoopTelemetryFactExporter $exporter = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock instanceof \Closure
            ? $clock
            : \Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));

        parent::__construct();
    }

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));
        $facts = $this->stream()->listFacts();

        return match ($action) {
            'tail' => $this->tail($facts),
            'aggregate' => $this->aggregate($facts),
            'export' => $this->export($facts),
            default => $this->unknownAction($action),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function tail(array $facts): int
    {
        $tail = array_map(
            static fn (array $fact): array => [
                'kind' => (string) ($fact['kind'] ?? ''),
                'occurred_at_iso' => (string) ($fact['occurred_at_iso'] ?? ''),
                'cycle_id' => (string) ($fact['cycle_id'] ?? ''),
            ],
            $facts
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode(['facts' => $tail]));

            return self::SUCCESS;
        }

        foreach ($tail as $fact) {
            $this->line(sprintf('%s %s %s', $fact['occurred_at_iso'], $fact['kind'], $fact['cycle_id']));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function aggregate(array $facts): int
    {
        $payload = $this->aggregator()->aggregate(
            $facts,
            ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            (int) $this->option('minutes')
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function export(array $facts): int
    {
        $path = trim((string) $this->option('path'));
        if ($path === '') {
            $this->error('The export action requires --path.');

            return self::FAILURE;
        }

        $written = 0;
        foreach ($facts as $fact) {
            if ($this->exporter()->append($path, $fact)) {
                $written++;
            }
        }

        $payload = [
            'path' => $path,
            'lines_written' => $written,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line(sprintf('%s %d', $path, $written));

        return self::SUCCESS;
    }

    private function unknownAction(string $action): int
    {
        $this->error('Unknown action: '.$action.'. Expected one of tail|aggregate|export.');

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function stream(): AtlasLoopTelemetryFactStreamEmitter
    {
        return $this->emitter ?? new AtlasLoopTelemetryFactStreamEmitter;
    }

    private function aggregator(): AtlasLoopTelemetryFactWindowAggregator
    {
        return $this->aggregator ?? new AtlasLoopTelemetryFactWindowAggregator;
    }

    private function exporter(): AtlasLoopTelemetryFactExporter
    {
        return $this->exporter ?? new AtlasLoopTelemetryFactExporter;
    }
}
