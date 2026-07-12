<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PlanVisible;

use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevOperatorInteractionTelemetry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDevOperatorInteractionTelemetryTest extends TestCase
{
    public function test_aggregates_operator_experience_without_quality_signal(): void
    {
        $result = (new AtlasDevOperatorInteractionTelemetry)->aggregate([
            ['kind' => 'question'],
            ['kind' => 'active_time', 'minutes' => 12.5],
            ['kind' => 'override'],
            ['kind' => 'cancellation'],
            ['kind' => 'handoff'],
            ['kind' => 'active_time', 'minutes' => 2.25],
        ]);

        self::assertSame(AtlasDevOperatorInteractionTelemetry::SCHEMA_VERSION, $result['schema_version']);
        self::assertSame('ok', $result['status']);
        self::assertSame([
            'questions' => 1,
            'overrides' => 1,
            'cancellations' => 1,
            'handoffs' => 1,
        ], $result['counts']);
        self::assertSame(14.75, $result['active_minutes']);
        self::assertSame(6, $result['event_count']);
        self::assertArrayNotHasKey('quality_score', $result);
        self::assertArrayNotHasKey('release_decision', $result);
    }

    public function test_empty_events_are_pending_data_not_zeroed_experience(): void
    {
        $result = (new AtlasDevOperatorInteractionTelemetry)->aggregate([]);

        self::assertSame('pending_data', $result['status']);
        self::assertSame(0, $result['event_count']);
        self::assertSame(0.0, $result['active_minutes']);
    }

    public function test_invalid_or_negative_measurements_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minutes_invalid');

        (new AtlasDevOperatorInteractionTelemetry)->aggregate([
            ['kind' => 'active_time', 'minutes' => -1],
        ]);
    }
}
