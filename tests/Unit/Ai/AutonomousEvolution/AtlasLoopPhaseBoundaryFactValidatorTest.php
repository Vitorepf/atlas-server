<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLoopPhaseBoundaryFactValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_a_valid_payload_for_a_real_boundary(): void
    {
        $validator = new AtlasLoopPhaseBoundaryFactValidator;

        $result = $validator->validate('orient->comprehend', [
            'scope_snapshot' => ['target' => 'app/Foo.php'],
            'surface_inventory' => ['controllers' => 1],
            'target_signal' => 'orphan_wiring',
            'emitted_by_phase' => 'orient',
            'emitted_at' => '2026-06-24T06:31:00+00:00',
            'cycle_id' => 'cycle-123',
        ]);

        self::assertTrue($result->ok);
        self::assertSame([], $result->missingKeys);
        self::assertSame([], $result->typeMismatches);
        self::assertSame([], $result->unknownKeys);
    }

    #[Test]
    public function it_reports_missing_keys_type_mismatches_and_unknown_keys(): void
    {
        $validator = new AtlasLoopPhaseBoundaryFactValidator;

        $result = $validator->validate('orient->comprehend', [
            'scope_snapshot' => 'should-be-array',
            'target_signal' => 'orphan_wiring',
            'emitted_by_phase' => 'orient',
            'emitted_at' => '2026-06-24T06:31:00+00:00',
            'cycle_id' => 'cycle-123',
            'extra_field' => true,
        ]);

        self::assertFalse($result->ok);
        self::assertSame(['surface_inventory'], $result->missingKeys);
        self::assertSame([
            [
                'key' => 'scope_snapshot',
                'expected_type' => 'array',
                'actual_type' => 'string',
            ],
        ], $result->typeMismatches);
        self::assertSame(['extra_field'], $result->unknownKeys);
    }

    #[Test]
    public function it_fails_closed_for_an_unknown_boundary(): void
    {
        $validator = new AtlasLoopPhaseBoundaryFactValidator;

        $result = $validator->validate('unknown->boundary', []);

        self::assertFalse($result->ok);
        self::assertSame('unknown_boundary', $result->reason);
    }
}
