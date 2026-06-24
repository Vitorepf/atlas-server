<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactSchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLoopPhaseBoundaryFactSchemaRegistryTest extends TestCase
{
    #[Test]
    public function it_returns_the_full_boundary_set(): void
    {
        $registry = new AtlasLoopPhaseBoundaryFactSchemaRegistry;

        self::assertSame([
            'orient->comprehend',
            'comprehend->decide-leverage',
            'decide-leverage->architect',
            'architect->decompose',
            'decompose->implement',
            'implement->certify',
            'certify->close-on-main',
        ], $registry->boundaries());
    }

    #[Test]
    public function it_returns_deterministic_non_empty_schema_arrays_for_every_boundary(): void
    {
        $registry = new AtlasLoopPhaseBoundaryFactSchemaRegistry;

        foreach ($registry->boundaries() as $boundary) {
            $first = $registry->schemaFor($boundary);
            $second = $registry->schemaFor($boundary);

            self::assertNotEmpty($first['required_keys']);
            self::assertContainsOnlyString($first['required_keys']);
            self::assertNotEmpty($first['field_types']);
            self::assertSame(serialize($first), serialize($second));
        }
    }
}
