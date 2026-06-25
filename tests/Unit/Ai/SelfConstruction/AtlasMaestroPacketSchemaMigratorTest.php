<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaMigrator;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\SchemaDowngradeRefusedException;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\UnknownSchemaVersionException;
use Tests\TestCase;

class AtlasMaestroPacketSchemaMigratorTest extends TestCase
{
    private function versioningWithSyntheticV2(): AtlasMaestroPacketSchemaVersioning
    {
        // Add a v2 successor to the canonical v1; register it with a successor pointer.
        $extra = [
            // Replace v1 with a row whose successor points to v2.
            [
                'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
                'version' => 1,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2025-12-01',
                'successor' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            ],
            [
                'id' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
                'version' => 2,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'evolution_hints'],
                'additive_fields' => ['evolution_hints'],
                'removed_fields' => [],
                'introduced_at' => '2026-06-25',
                'successor' => null,
            ],
        ];

        return new AtlasMaestroPacketSchemaVersioning($extra);
    }

    private function buildMigrator(): AtlasMaestroPacketSchemaMigrator
    {
        $clock = static fn (): string => '2026-06-25T00:00:00Z';
        $migrator = new AtlasMaestroPacketSchemaMigrator($this->versioningWithSyntheticV2(), $clock);
        $migrator->registerTransform(
            AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'atlas.self_construction.agent_control_plane_task_packet.v2',
            'add_evolution_hints',
            static function (array $packet): array {
                $packet['evolution_hints'] = ['default_strategy' => 'forward_only'];

                return $packet;
            },
        );

        return $migrator;
    }

    private function v1Packet(): array
    {
        return [
            'schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'task_packet_id' => 'pk-1',
            'objective' => 'noop',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['noop'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    public function test_migrate_v1_to_v2_applies_transform_and_records_trail(): void
    {
        $out = $this->buildMigrator()->migrateTo(
            $this->v1Packet(),
            'atlas.self_construction.agent_control_plane_task_packet.v2',
        );

        self::assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $out['schema_version']);
        self::assertSame(['default_strategy' => 'forward_only'], $out['evolution_hints']);
        self::assertCount(1, $out['migration_trail']);
        $hop = $out['migration_trail'][0];
        self::assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $hop['from']);
        self::assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $hop['to']);
        self::assertSame('add_evolution_hints', $hop['transform_id']);
        self::assertSame('2026-06-25T00:00:00Z', $hop['applied_at']);
    }

    public function test_migrate_unknown_source_throws_unknown_schema_version(): void
    {
        $this->expectException(UnknownSchemaVersionException::class);
        $this->expectExceptionMessage('unknown_source_schema_version');

        $packet = $this->v1Packet();
        $packet['schema_version'] = 'mystery.v9';

        $this->buildMigrator()->migrateTo($packet, 'atlas.self_construction.agent_control_plane_task_packet.v2');
    }

    public function test_migrate_v2_to_v1_is_refused_as_downgrade(): void
    {
        $this->expectException(SchemaDowngradeRefusedException::class);
        $this->expectExceptionMessage('downgrade_refused');

        $migrator = $this->buildMigrator();
        $v2Packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            'task_packet_id' => 'pk-2',
            'objective' => 'noop',
            'allowed_files' => ['x'],
            'acceptance_criteria' => ['x'],
            'required_evidence' => ['x'],
            'evolution_hints' => [],
        ];

        $migrator->migrateTo($v2Packet, AtlasMaestroPacketSchemaVersioning::CANONICAL_V1);
    }

    public function test_migrate_is_byte_identical_for_identical_input_with_frozen_clock(): void
    {
        $migrator = $this->buildMigrator();
        $packet = $this->v1Packet();
        $a = $migrator->migrateTo($packet, 'atlas.self_construction.agent_control_plane_task_packet.v2');
        $b = $migrator->migrateTo($packet, 'atlas.self_construction.agent_control_plane_task_packet.v2');

        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_no_op_migration_when_source_equals_target_keeps_packet_unchanged(): void
    {
        $migrator = $this->buildMigrator();
        $packet = $this->v1Packet();
        $out = $migrator->migrateTo($packet, AtlasMaestroPacketSchemaVersioning::CANONICAL_V1);

        self::assertSame($packet, $out);
    }
}
