<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaMigrator;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;

final class AtlasMaestroPacketSchemaMigratorHardeningTest extends TestCase
{
    private function versioning(): AtlasMaestroPacketSchemaVersioning
    {
        $extra = [
            [
                'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
                'version' => 1,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id'],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2025-12-01',
                'successor' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            ],
            [
                'id' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
                'version' => 2,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id'],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2026-01-01',
                'successor' => null,
            ],
        ];
        return new AtlasMaestroPacketSchemaVersioning($extra);
    }

    /**
     * A transform that returns a non-array must throw, not silently wipe.
     */
    public function test_non_array_transform_throws(): void
    {
        $migrator = new AtlasMaestroPacketSchemaMigrator($this->versioning());
        $migrator->registerTransform(
            AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'atlas.self_construction.agent_control_plane_task_packet.v2',
            'test-transform',
            fn () => null, // buggy transform returns null
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('packet_schema_migrator_transform_non_array:');

        $migrator->migrateTo(['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1], 'atlas.self_construction.agent_control_plane_task_packet.v2');
    }

    /**
     * A valid transform still works.
     */
    public function test_valid_transform_works(): void
    {
        $migrator = new AtlasMaestroPacketSchemaMigrator($this->versioning());
        $migrator->registerTransform(
            AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'atlas.self_construction.agent_control_plane_task_packet.v2',
            'test-transform',
            fn (array $packet) => $packet,
        );

        $result = $migrator->migrateTo(['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1], 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $result['schema_version']);
    }

    /**
     * Verify the source has the guard.
     */
    public function test_source_has_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/PacketEvolution/AtlasMaestroPacketSchemaMigrator.php');

        $this->assertStringContainsString('is_array($transformed)', $source);
        $this->assertStringContainsString('RuntimeException', $source);
    }
}
