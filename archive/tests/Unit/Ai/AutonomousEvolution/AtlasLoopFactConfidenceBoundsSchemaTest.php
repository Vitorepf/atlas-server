<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsSchema;
use InvalidArgumentException;
use ReflectionProperty;
use Tests\TestCase;

final class AtlasLoopFactConfidenceBoundsSchemaTest extends TestCase
{
    public function test_schema_rejects_invalid_inputs_and_accepts_happy_path(): void
    {
        try {
            AtlasLoopFactConfidenceBoundsSchema::make(true, 0, 1);
            $this->fail('Expected positive sample size guard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('fact_confidence_sample_size_must_be_positive', $e->getMessage());
        }

        try {
            AtlasLoopFactConfidenceBoundsSchema::make(true, 1, 0);
            $this->fail('Expected positive source count guard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('fact_confidence_source_count_must_be_positive', $e->getMessage());
        }

        try {
            AtlasLoopFactConfidenceBoundsSchema::make('true', 1, 1);
            $this->fail('Expected bool value guard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('fact_confidence_value_must_be_bool', $e->getMessage());
        }

        $schema = AtlasLoopFactConfidenceBoundsSchema::make(true, 30, 5);
        $this->assertSame([
            'sample_size' => 30,
            'source_count' => 5,
            'value' => true,
        ], $schema->toArray());
    }

    public function test_from_legacy_preserves_legacy_binary_json_projection(): void
    {
        $schema = AtlasLoopFactConfidenceBoundsSchema::fromLegacy(true);
        $fixture = 'true';

        $this->assertSame([
            'sample_size' => 1,
            'source_count' => 1,
            'value' => true,
        ], $schema->toArray());
        $this->assertSame($fixture, $schema->toJson(true));
        $this->assertSame(hash('sha256', $fixture), hash('sha256', $schema->toJson(true)));
    }

    public function test_envelope_is_immutable_and_equality_is_structural(): void
    {
        $left = AtlasLoopFactConfidenceBoundsSchema::make(false, 12, 3);
        $right = AtlasLoopFactConfidenceBoundsSchema::make(false, 12, 3);

        $this->assertTrue($left->equals($right));
        $this->assertNotSame($left, $right);

        $property = new ReflectionProperty($left->envelope, 'value');
        $this->expectExceptionMessageMatches('/readonly/');
        $property->setValue($left->envelope, true);
    }
}
