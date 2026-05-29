<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\RealityCompilerSlice;
use Tests\TestCase;

final class RealityCompilerSliceTest extends TestCase
{
    public function test_reality_compiler_slice_default_shape(): void
    {
        $slice = RealityCompilerSlice::defaultShape();

        $this->assertSame(RealityCompilerSlice::SCHEMA_VERSION, $slice->toArray()['schema_version']);
        $this->assertSame('', $slice->intent);
        $this->assertSame('L0', $slice->autonomyLevel);
        $this->assertCount(count(RealityCompilerSlice::EXECUTION_PHASES), $slice->outputPhases);
        $this->assertSame(
            RealityCompilerSlice::EXECUTION_PHASES,
            array_column($slice->outputPhases, 'phase'),
        );
        foreach ($slice->outputPhases as $phase) {
            $this->assertSame('pending', $phase['status']);
        }
    }

    public function test_reality_compiler_slice_construction(): void
    {
        $slice = new RealityCompilerSlice(
            intent: 'compile checkout flow into governed packets',
            autonomyLevel: 'L2',
            outputPhases: [
                ['phase' => 'spec', 'status' => 'pending'],
                ['phase' => 'simulation', 'status' => 'pending'],
            ],
        );

        $this->assertSame('compile checkout flow into governed packets', $slice->intent);
        $this->assertSame('L2', $slice->autonomyLevel);
        $this->assertSame(
            [
                'schema_version' => RealityCompilerSlice::SCHEMA_VERSION,
                'intent' => 'compile checkout flow into governed packets',
                'autonomy_level' => 'L2',
                'output_phases' => [
                    ['phase' => 'spec', 'status' => 'pending'],
                    ['phase' => 'simulation', 'status' => 'pending'],
                ],
            ],
            $slice->toArray(),
        );
    }
}
