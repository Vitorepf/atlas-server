<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SchemaFuzz;

use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzPayloadGenerator;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaFuzzPayloadGeneratorTest extends TestCase
{
    public function test_same_seed_and_schema_produce_byte_identical_sequences(): void
    {
        $generator = new AtlasLoopSchemaFuzzPayloadGenerator;

        $first = serialize($generator->variants(7, 'decision_receipt'));
        $second = serialize($generator->variants(7, 'decision_receipt'));

        $this->assertSame($first, $second);
    }

    public function test_three_schema_descriptors_cover_all_boundary_kinds(): void
    {
        $generator = new AtlasLoopSchemaFuzzPayloadGenerator;
        $expected = [
            'missing_required',
            'null_vs_absent',
            'type_mismatch',
            'oversize',
            'numeric_extreme',
            'unicode_control',
            'recursion_limit',
            'additional_properties',
        ];

        foreach (['decision_receipt', 'task_envelope', 'attempt_ledger_record'] as $schemaName) {
            $variants = $generator->variants(11, $schemaName);
            $actual = array_values(array_unique(array_map(
                static fn (array $variant): string => (string) $variant['boundary_kind'],
                $variants,
            )));
            sort($actual);
            $sortedExpected = $expected;
            sort($sortedExpected);

            $this->assertSame($sortedExpected, $actual, $schemaName.' must cover all boundary kinds');
        }
    }

    public function test_generator_is_pure_and_emits_facts_only_rows(): void
    {
        $generatorA = new AtlasLoopSchemaFuzzPayloadGenerator;
        usleep(1000);
        $generatorB = new AtlasLoopSchemaFuzzPayloadGenerator;

        $first = $generatorA->variants(3, 'task_envelope');
        $second = $generatorB->variants(3, 'task_envelope');
        $this->assertSame(serialize($first), serialize($second));

        foreach ($first as $row) {
            $this->assertSame(
                ['payload_id', 'seed', 'schema_name', 'boundary_kind', 'expected_violation_facet', 'payload'],
                array_keys($row),
            );
            $this->assertArrayNotHasKey('score', $row);
            $this->assertArrayNotHasKey('rank', $row);
            $this->assertArrayNotHasKey('quality', $row);
        }

        $sourcePath = __DIR__.'/../../../../../app/Services/Ai/AutonomousEvolution/SchemaFuzz/AtlasLoopSchemaFuzzPayloadGenerator.php';
        $source = (string) file_get_contents($sourcePath);
        $this->assertSame(0, preg_match('/Carbon::now|date\\(|random_int|mt_rand|file_put_contents|fopen|fwrite/', $source));
    }
}
