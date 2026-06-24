<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactStreamEmitter;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopTelemetryFactStreamEmitterTest extends TestCase
{
    public function test_happy_path_emits_one_fact_for_each_allowed_kind(): void
    {
        $ticks = 0;
        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            null,
            static function () use (&$ticks): DateTimeImmutable {
                $ticks++;

                return new DateTimeImmutable(sprintf('2026-06-24T00:00:%02d+00:00', $ticks), new DateTimeZone('UTC'));
            }
        );

        foreach (['claim', 'lease', 'serve', 'report', 'merge'] as $kind) {
            $fact = $emitter->emit($kind, ['scope' => 'loop', 'value' => $kind], 'cycle-1');

            $this->assertSame('cycle-1', $fact['cycle_id']);
            $this->assertSame($kind, $fact['kind']);
            $this->assertSame('loop', $fact['scope']);
            $this->assertSame('atlas.loop.telemetry.fact.v1', $fact['schema_version']);
            $this->assertSame(['scope' => 'loop', 'value' => $kind], $fact['payload']);
        }

        $this->assertCount(5, $emitter->listFacts());
        $this->assertSame(['claim', 'lease', 'serve', 'report', 'merge'], array_column($emitter->listFacts(), 'kind'));
    }

    public function test_unknown_kind_is_rejected_without_writing(): void
    {
        $emitter = new AtlasLoopTelemetryFactStreamEmitter;

        $this->assertNull($emitter->emit('unknown', ['scope' => 'loop'], 'cycle-2'));
        $this->assertSame([], $emitter->listFacts());
    }

    public function test_sink_throwing_does_not_propagate_and_does_not_corrupt_buffer(): void
    {
        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            static function (array $fact): void {
                throw new RuntimeException('transport down');
            },
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-24T00:00:09+00:00', new DateTimeZone('UTC'))
        );

        $fact = $emitter->emit('serve', ['scope' => 'loop', 'value' => 'ok'], 'cycle-3');

        $this->assertSame('serve', $fact['kind']);
        $this->assertCount(1, $emitter->listFacts());
        $this->assertSame($fact, $emitter->listFacts()[0]);
    }

    public function test_encoded_fact_payload_never_contains_score_or_rank_fields(): void
    {
        $emitter = new AtlasLoopTelemetryFactStreamEmitter(
            null,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-24T00:00:10+00:00', new DateTimeZone('UTC'))
        );

        foreach (['claim', 'lease', 'serve', 'report', 'merge'] as $kind) {
            $emitter->emit($kind, ['scope' => 'loop', 'channel' => $kind], 'cycle-4');
        }

        foreach ($emitter->listFacts() as $fact) {
            $encoded = json_encode($fact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertDoesNotMatchRegularExpression('/"[^"]*(score|rank)[^"]*"\s*:/i', $encoded);
        }
    }
}
