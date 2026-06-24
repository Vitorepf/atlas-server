<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopLivingSignalAggregator;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasLoopLivingSignalAggregatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_aggregate_returns_byte_identical_snapshot_and_deterministic_content_hash_for_identical_inputs(): void
    {
        $aggregator = $this->aggregator(
            operatorIntent: $this->port([['symbol' => 'App\\Foo', 'fact' => 'accepted']]),
            cortexMeaning: $this->port([['symbols' => ['App\\Foo', 'App\\Bar'], 'fact' => 'comprehended']]),
            maestroOutcomes: $this->port([['references' => ['App\\Foo'], 'fact' => 'merged']]),
            loopTelemetry: $this->port([['cited_symbols' => ['App\\Foo'], 'fact' => 'certified']]),
        );

        $first = $aggregator->aggregate(CarbonInterval::hours(6))->toArray();
        $second = $aggregator->aggregate(CarbonInterval::hours(6))->toArray();

        $this->assertSame($first, $second);
        $this->assertSame($first['content_hash'], $second['content_hash']);
        $this->assertSame(
            ['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent'],
            array_keys($first['sources'])
        );
    }

    public function test_aggregate_has_zero_write_side_effects_outside_the_injected_fact_ports(): void
    {
        $operator = $this->port([['symbol' => 'App\\Foo']]);
        $cortex = $this->port([['symbol' => 'App\\Foo']]);
        $maestro = $this->port([['symbol' => 'App\\Foo']]);
        $telemetry = $this->port([['symbol' => 'App\\Foo']]);

        $this->aggregator($operator, $cortex, $maestro, $telemetry)
            ->aggregate(CarbonInterval::minutes(30));

        $this->assertSame(0, $operator->writeCount);
        $this->assertSame(0, $cortex->writeCount);
        $this->assertSame(0, $maestro->writeCount);
        $this->assertSame(0, $telemetry->writeCount);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_empty_source_records_empty_status_and_null_coherence_without_synthesis(): void
    {
        $snapshot = $this->aggregator(
            operatorIntent: $this->port([['symbol' => 'App\\Foo']]),
            cortexMeaning: $this->port([]),
            maestroOutcomes: $this->port([['symbol' => 'App\\Foo']]),
            loopTelemetry: $this->port([['symbol' => 'App\\Foo']]),
        )->aggregate(CarbonInterval::day())->toArray();

        $this->assertSame('empty', $snapshot['source_status']['cortex_meaning']);
        $this->assertNull($snapshot['coherence']);
        $this->assertSame([], $snapshot['sources']['cortex_meaning']);
    }

    private function aggregator(object $operatorIntent, object $cortexMeaning, object $maestroOutcomes, object $loopTelemetry): AtlasLoopLivingSignalAggregator
    {
        return new AtlasLoopLivingSignalAggregator(
            operatorIntent: $operatorIntent,
            cortexMeaning: $cortexMeaning,
            maestroOutcomes: $maestroOutcomes,
            loopTelemetry: $loopTelemetry,
            clock: fn () => '2026-06-24T12:00:00+00:00',
        );
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     */
    private function port(array $facts): object
    {
        return new class($facts)
        {
            public int $writeCount = 0;

            /**
             * @param  list<array<string,mixed>>  $facts
             */
            public function __construct(
                private readonly array $facts,
            ) {}

            /**
             * @return list<array<string,mixed>>
             */
            public function facts(string $windowStart, string $windowEnd): array
            {
                return $this->facts;
            }

            public function write(mixed $payload): void
            {
                $this->writeCount++;
            }
        };
    }
}
