<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\PacketEvolution;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaMigrator;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\SchemaDowngradeRefusedException;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\UnknownSchemaVersionException;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketSchemaMigratorTest extends TestCase
{
    private function versioningWithSyntheticV2(): AtlasMaestroPacketSchemaVersioning
    {
        $extra = [
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

    private function buildMigrator(?callable $transform = null): AtlasMaestroPacketSchemaMigrator
    {
        $clock = static fn (): string => '2026-06-25T00:00:00Z';
        $migrator = new AtlasMaestroPacketSchemaMigrator($this->versioningWithSyntheticV2(), $clock);
        $migrator->registerTransform(
            AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'atlas.self_construction.agent_control_plane_task_packet.v2',
            'add_evolution_hints',
            $transform ?? static function (array $packet): array {
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

    // ── AC2: unknown target version refused ────────────────────────────────────

    public function test_migrate_unknown_target_throws_unknown_schema_version(): void
    {
        $this->expectException(UnknownSchemaVersionException::class);
        $this->expectExceptionMessage('unknown_target_schema_version');

        $this->buildMigrator()->migrateTo($this->v1Packet(), 'mystery.v9');
    }

    public function test_migrate_downgrade_message_names_both_versions(): void
    {
        $migrator = $this->buildMigrator();
        $v2Packet = array_merge($this->v1Packet(), [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            'evolution_hints' => [],
        ]);

        try {
            $migrator->migrateTo($v2Packet, AtlasMaestroPacketSchemaVersioning::CANONICAL_V1);
            $this->fail('expected SchemaDowngradeRefusedException');
        } catch (SchemaDowngradeRefusedException $e) {
            $this->assertStringContainsString(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $e->getMessage());
            $this->assertStringContainsString('atlas.self_construction.agent_control_plane_task_packet.v2', $e->getMessage());
        }
    }

    // ── AC3: idempotent at target version ──────────────────────────────────────

    public function test_migration_at_target_version_is_a_true_no_op_including_migration_trail(): void
    {
        $migrator = $this->buildMigrator();
        $packet = $this->v1Packet();
        $packet['migration_trail'] = [['from' => 'x', 'to' => 'y', 'transform_id' => 'z', 'applied_at' => 'then']];

        $out = $migrator->migrateTo($packet, AtlasMaestroPacketSchemaVersioning::CANONICAL_V1);

        $this->assertSame($packet, $out, 'already-at-target packet must return byte-identical, including any existing trail');
    }

    public function test_repeated_migration_to_same_target_is_idempotent(): void
    {
        $migrator = $this->buildMigrator();
        $packet = $this->v1Packet();

        $once = $migrator->migrateTo($packet, 'atlas.self_construction.agent_control_plane_task_packet.v2');
        $twice = $migrator->migrateTo($once, 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame($once, $twice, 'migrating an already-migrated packet to the same target must be a no-op');
    }

    // ── AC4: preserves objective, allowed_files, acceptance_criteria, required_evidence ──

    public function test_objective_is_lossless_when_a_transform_drops_it(): void
    {
        $migrator = $this->buildMigrator(static fn (array $p): array => [
            'task_packet_id' => $p['task_packet_id'],
            'allowed_files' => $p['allowed_files'],
            'acceptance_criteria' => $p['acceptance_criteria'],
            'required_evidence' => $p['required_evidence'],
            'evolution_hints' => [],
            // objective deliberately dropped
        ]);

        $out = $migrator->migrateTo($this->v1Packet(), 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame('noop', $out['objective'], 'objective must survive a transform that drops it');
    }

    public function test_explicit_objective_change_is_allowed_and_receipted_on_the_trail_hop(): void
    {
        $migrator = $this->buildMigrator(static fn (array $p): array => [
            'task_packet_id' => $p['task_packet_id'],
            'objective' => 'rewritten objective',
            'allowed_files' => $p['allowed_files'],
            'acceptance_criteria' => $p['acceptance_criteria'],
            'required_evidence' => $p['required_evidence'],
            'evolution_hints' => [],
        ]);

        $out = $migrator->migrateTo($this->v1Packet(), 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame('rewritten objective', $out['objective'], 'a transform is allowed to explicitly change objective');
        $this->assertContains('objective', $out['migration_trail'][0]['changed_protected_fields']);
    }

    public function test_unchanged_protected_fields_produce_an_empty_receipt(): void
    {
        $out = $this->buildMigrator()->migrateTo($this->v1Packet(), 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame([], $out['migration_trail'][0]['changed_protected_fields']);
    }

    public function test_explicit_acceptance_criteria_change_is_receipted(): void
    {
        $migrator = $this->buildMigrator(static fn (array $p): array => [
            'task_packet_id' => $p['task_packet_id'],
            'objective' => $p['objective'],
            'allowed_files' => $p['allowed_files'],
            'acceptance_criteria' => ['a new criterion'],
            'required_evidence' => $p['required_evidence'],
            'evolution_hints' => [],
        ]);

        $out = $migrator->migrateTo($this->v1Packet(), 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $this->assertSame(['a new criterion'], $out['acceptance_criteria']);
        $this->assertContains('acceptance_criteria', $out['migration_trail'][0]['changed_protected_fields']);
    }
}
