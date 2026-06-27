<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathDiversityScore;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSchemaContractRegistry;
use Tests\TestCase;

final class AtlasBrainSchemaContractRegistryTest extends TestCase
{
    public function test_owner_of_known_schema(): void
    {
        $r = new AtlasBrainSchemaContractRegistry;
        self::assertSame(AtlasBrainPathDiversityScore::class, $r->ownerOf(AtlasBrainPathDiversityScore::SCHEMA));
    }

    public function test_unknown_schema_returns_null(): void
    {
        self::assertNull((new AtlasBrainSchemaContractRegistry)->ownerOf('atlas.brain.does_not_exist.v1'));
    }

    public function test_all_returns_at_least_14_pairs(): void
    {
        $all = (new AtlasBrainSchemaContractRegistry)->all();
        self::assertGreaterThanOrEqual(14, count($all));
        foreach ($all as $row) {
            self::assertTrue(class_exists($row['owner']));
            self::assertNotEmpty($row['schema']);
        }
    }

    public function test_no_duplicate_schemas(): void
    {
        $schemas = array_column((new AtlasBrainSchemaContractRegistry)->all(), 'schema');
        self::assertSame(count($schemas), count(array_unique($schemas)));
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSchemaContractRegistry.php',
                true
            )
        );
    }
}
