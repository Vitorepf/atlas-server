<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\PacketEvolution;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaDeprecationGate;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use Tests\TestCase;

final class AtlasMaestroPacketSchemaDeprecationGateTest extends TestCase
{
    protected function tearDown(): void
    {
        AtlasMaestroPacketSchemaDeprecationGate::reset();
        parent::tearDown();
    }

    private function deprecateV1Registry(?string $successor = null): void
    {
        $v1Deprecated = [
            'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'version' => 1,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2025-12-01',
            'successor' => $successor,
        ];
        $rows = [$v1Deprecated];
        if ($successor !== null) {
            $rows[] = [
                'id' => $successor,
                'version' => 2,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => [],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2026-01-01',
                'successor' => null,
            ];
        }
        AtlasMaestroPacketSchemaDeprecationGate::$registryOverride = new AtlasMaestroPacketSchemaVersioning(
            extraVersions: $rows,
        );
    }

    private function retireV1Registry(): void
    {
        $v1Retired = [
            'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'version' => 1,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2025-12-01',
            'successor' => null,
        ];
        AtlasMaestroPacketSchemaDeprecationGate::$registryOverride = new AtlasMaestroPacketSchemaVersioning(
            extraVersions: [$v1Retired],
        );
    }

    private function packet(): array
    {
        return ['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1];
    }

    // ── AC1: supported (active) schemas are allowed ─────────────────────────

    public function test_active_schema_is_servable_with_no_blockers(): void
    {
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertTrue($verdict['servable']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE, $verdict['schema_status']);
        $this->assertFalse($verdict['metadata_missing']);
    }

    // ── AC2: unknown schemas are marked explicitly ──────────────────────────

    public function test_missing_schema_version_is_marked_unknown_with_metadata_missing_true(): void
    {
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check([]);

        $this->assertSame('unknown', $verdict['schema_status']);
        $this->assertTrue($verdict['metadata_missing']);
    }

    public function test_unrecognized_schema_id_is_marked_unknown_with_metadata_missing_true(): void
    {
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check(['schema_version' => 'not.a.registered.schema.v99']);

        $this->assertSame('unknown', $verdict['schema_status']);
        $this->assertSame('not.a.registered.schema.v99', $verdict['schema_id']);
        $this->assertTrue($verdict['metadata_missing']);
    }

    // ── AC4: missing schema metadata is never treated as proof of safety ───

    public function test_known_active_schema_never_reports_metadata_missing(): void
    {
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertFalse($verdict['metadata_missing']);
    }

    public function test_unknown_schema_servable_true_still_carries_metadata_missing_flag(): void
    {
        // servable=true for unknown is a permissive default (unknown != deprecated), but the
        // metadata_missing flag ensures no caller reads servable=true as a proof of safety.
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check(['schema_version' => 'brand.new.unregistered.v1']);

        $this->assertTrue($verdict['servable']);
        $this->assertTrue($verdict['metadata_missing']);
    }

    // ── AC2/AC3: deprecated schemas without a migration path are refused ───

    public function test_deprecated_schema_without_successor_is_refused_with_blocker_details(): void
    {
        $this->deprecateV1Registry(successor: null);

        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertFalse($verdict['servable']);
        $this->assertNotEmpty($verdict['blockers']);
        $this->assertNull($verdict['recommended_migration_target']);
        $this->assertNotEmpty($verdict['blocker_details']);
        $detail = $verdict['blocker_details'][0];
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $detail['schema_id']);
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, $detail['schema_status']);
        $this->assertNull($detail['recommended_migration_target']);
    }

    public function test_retired_schema_is_refused_with_blocker_details(): void
    {
        $this->retireV1Registry();

        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertFalse($verdict['servable']);
        $this->assertStringContainsString('retired_schema_not_servable:', implode(',', $verdict['blockers']));
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED, $verdict['blocker_details'][0]['schema_status']);
    }

    // ── AC2: deprecated schema WITH a lossless migration path is servable, and
    // the recommended migration target is surfaced even when no blocker fires ──

    public function test_deprecated_schema_with_active_successor_is_servable_and_names_migration_target(): void
    {
        $this->deprecateV1Registry(successor: 'atlas.self_construction.agent_control_plane_task_packet.v2');

        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertTrue($verdict['servable']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $verdict['recommended_migration_target']);
    }

    public function test_deprecated_schema_whose_successor_is_also_deprecated_is_refused(): void
    {
        $v1 = [
            'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'version' => 1,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2025-12-01',
            'successor' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
        ];
        $v2 = [
            'id' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            'version' => 2,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2026-01-01',
            'successor' => null,
        ];
        AtlasMaestroPacketSchemaDeprecationGate::$registryOverride = new AtlasMaestroPacketSchemaVersioning(extraVersions: [$v1, $v2]);

        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertFalse($verdict['servable']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_check_is_deterministic(): void
    {
        $this->deprecateV1Registry(successor: null);

        $a = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());
        $b = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertSame($a, $b);
    }
}
