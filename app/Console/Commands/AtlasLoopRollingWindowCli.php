<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactStreamEmitter;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowAggregator;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowComparator;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowReceiptLedger;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AtlasLoopRollingWindowCli extends Command
{
    protected $signature = 'atlas:loop:telemetry:windows
        {action : inspect|compare|history}
        {--window=1h}
        {--at=}
        {--since=}
        {--json}';

    protected $description = 'Read-only operator surface for Loop telemetry rolling windows. FACTS only.';

    /** @var \Closure(): DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly ?AtlasLoopTelemetryFactStreamEmitter $emitter = null,
        private readonly ?AtlasLoopRollingWindowAggregator $aggregator = null,
        private readonly ?AtlasLoopRollingWindowComparator $comparator = null,
        private readonly ?AtlasLoopRollingWindowReceiptLedger $ledger = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock instanceof \Closure
            ? $clock
            : \Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));

        parent::__construct();
    }

    public function handle(): int
    {
        $window = trim((string) $this->option('window'));
        if (! in_array($window, ['1h', '6h', '24h'], true)) {
            return $this->invalid('invalid_window', ['window' => $window]);
        }

        return match (trim((string) $this->argument('action'))) {
            'inspect' => $this->inspect($window),
            'compare' => $this->compare($window),
            'history' => $this->history($window),
            default => $this->invalid('unknown_action', ['action' => (string) $this->argument('action')]),
        };
    }

    private function inspect(string $window): int
    {
        $snapshot = $this->bucketForWindow($window, $this->facts(), $this->resolveAt());
        $this->ledger()->recordSnapshot($snapshot);

        return $this->emit($snapshot, self::SUCCESS);
    }

    private function compare(string $window): int
    {
        $facts = $this->facts();
        $current = $this->bucketForWindow($window, $facts, $this->resolveAt());
        $previousAt = (new DateTimeImmutable($current['window_start_iso'], new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT1S'))
            ->format(DATE_ATOM);
        $previous = $this->bucketForWindow($window, $facts, $previousAt);
        $delta = $this->comparator()->compare($current, $previous);

        return $this->emit($delta, self::SUCCESS);
    }

    private function history(string $window): int
    {
        $since = trim((string) $this->option('since'));
        if ($since === '') {
            $since = '1970-01-01T00:00:00+00:00';
        }

        $payload = [
            'receipts' => $this->ledger()->list($window, $since),
            'window_label' => $window,
        ];

        return $this->emit($payload, self::SUCCESS);
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>
     */
    private function bucketForWindow(string $window, array $facts, string $atIso): array
    {
        $aggregated = $this->aggregator()->aggregate($facts, $atIso);
        foreach ((array) ($aggregated['buckets'] ?? []) as $bucket) {
            if (is_array($bucket) && (string) ($bucket['window_label'] ?? '') === $window) {
                return $bucket;
            }
        }

        throw new InvalidArgumentException('window_bucket_not_found');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function facts(): array
    {
        return $this->emitter()->listFacts();
    }

    private function resolveAt(): string
    {
        $at = trim((string) $this->option('at'));
        if ($at !== '') {
            return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }

        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        if ((bool) $this->option('json')) {
            $this->line($encoded);

            return $exitCode;
        }

        if (isset($payload['count_delta'], $payload['duration_delta_ms'])) {
            $this->line(sprintf(
                '%s %s %s',
                (string) ($payload['window_label'] ?? ''),
                (string) ($payload['previous_window_start_iso'] ?? ''),
                (string) ($payload['current_window_start_iso'] ?? '')
            ));

            return $exitCode;
        }

        if (isset($payload['receipts']) && is_array($payload['receipts'])) {
            foreach ($payload['receipts'] as $receipt) {
                if (! is_array($receipt)) {
                    continue;
                }

                $this->line(sprintf(
                    '%s %s %s',
                    (string) ($receipt['window_start_iso'] ?? ''),
                    (string) ($receipt['window_end_iso'] ?? ''),
                    (string) ($receipt['payload_sha256'] ?? '')
                ));
            }

            return $exitCode;
        }

        $this->line(sprintf(
            '%s %s %s',
            (string) ($payload['window_label'] ?? ''),
            (string) ($payload['window_start_iso'] ?? ''),
            (string) ($payload['window_end_iso'] ?? '')
        ));

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function invalid(string $error, array $context): int
    {
        $payload = ['error' => $error] + $context;
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"error":"invalid"}';

        $this->line($line);

        return 2;
    }

    private function emitter(): AtlasLoopTelemetryFactStreamEmitter
    {
        return $this->emitter ?? new AtlasLoopTelemetryFactStreamEmitter;
    }

    private function aggregator(): AtlasLoopRollingWindowAggregator
    {
        return $this->aggregator ?? new AtlasLoopRollingWindowAggregator;
    }

    private function comparator(): AtlasLoopRollingWindowComparator
    {
        return $this->comparator ?? new AtlasLoopRollingWindowComparator;
    }

    private function ledger(): AtlasLoopRollingWindowReceiptLedger
    {
        return $this->ledger ?? new AtlasLoopRollingWindowReceiptLedger;
    }
}
