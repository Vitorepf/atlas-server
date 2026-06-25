<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\WireFormat\UnknownSchemaException;
use Tests\TestCase;

final class AtlasLoopInterPrimitiveMessageSchemaRegistryTest extends TestCase
{
    private const EXPECTED_SCHEMA_IDS = [
        'loop.cortex.snapshot.v1',
        'cortex.maestro.fact.v1',
        'maestro.loop.outcome.v1',
        'loop.cortex.scope_comprehension.v1',
        'cortex.loop.origination_seed.v1',
    ];

    public function test_registry_declares_all_named_schemas(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $ids = $registry->all();
        foreach (self::EXPECTED_SCHEMA_IDS as $id) {
            $this->assertContains($id, $ids, "missing required schema id: {$id}");
        }
    }

    public function test_get_returns_schema_definition_with_required_fields_and_types(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $schema = $registry->get('loop.cortex.snapshot.v1');

        $this->assertSame('loop.cortex.snapshot.v1', $schema['id']);
        $this->assertSame('loop.cortex.snapshot', $schema['family']);
        $this->assertSame(1, $schema['version']);
        $this->assertArrayHasKey('inventory', $schema['required_fields']);
        $this->assertSame('object', $schema['required_fields']['inventory']['type']);
        $this->assertFalse($schema['required_fields']['inventory']['nullable']);
        $this->assertSame('array', $schema['required_fields']['edges']['type']);
        $this->assertSame('scalar', $schema['required_fields']['snapshot_id']['type']);
        $this->assertNotEmpty($schema['byte_shape']);
    }

    public function test_unknown_schema_id_throws_typed_exception(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $this->expectException(UnknownSchemaException::class);
        $registry->get('definitely.not.a.schema.v99');
    }

    public function test_latest_for_returns_highest_version_in_family(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $latest = $registry->latestFor('loop.cortex.snapshot');
        $this->assertNotNull($latest);
        $this->assertSame('loop.cortex.snapshot.v1', $latest['id']);
        $this->assertSame(1, $latest['version']);
    }

    public function test_latest_for_returns_null_when_family_unknown(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $this->assertNull($registry->latestFor('not.a.real.family'));
    }

    public function test_versions_of_returns_ascending_version_list(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $rows = $registry->versionsOf('loop.cortex.snapshot');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['version']);
    }

    public function test_snapshot_of_required_field_sets_pins_each_schema(): void
    {
        $registry = new AtlasLoopInterPrimitiveMessageSchemaRegistry();
        $pinned = [
            'loop.cortex.snapshot.v1' => ['schema_id', 'snapshot_id', 'scope_root', 'built_at_unix', 'inventory', 'edges', 'orphans'],
            'cortex.maestro.fact.v1' => ['schema_id', 'fact_id', 'source_snapshot_id', 'observed_at_unix', 'kind', 'payload'],
            'maestro.loop.outcome.v1' => ['schema_id', 'task_packet_id', 'outcome', 'verified_chain', 'completed_at_unix', 'evidence_refs', 'error'],
            'loop.cortex.scope_comprehension.v1' => ['schema_id', 'scope_root', 'comprehension_id', 'inventory_size', 'orphan_count', 'forbidden_roots'],
            'cortex.loop.origination_seed.v1' => ['schema_id', 'seed_id', 'scope_root', 'rationale', 'evidence_refs', 'created_at_unix'],
        ];
        foreach ($pinned as $id => $expected) {
            $schema = $registry->get($id);
            $actual = array_keys($schema['required_fields']);
            sort($actual, SORT_STRING);
            $expectedSorted = $expected;
            sort($expectedSorted, SORT_STRING);
            $this->assertSame($expectedSorted, $actual, "required-field set drift on {$id}");
        }
    }

    public function test_registry_is_pure_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/WireFormat/AtlasLoopInterPrimitiveMessageSchemaRegistry.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'DB::', 'Http::', 'curl_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "registry must NOT contain {$forbidden}");
        }
    }
}
