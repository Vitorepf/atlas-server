<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use Tests\TestCase;

final class AtlasMaestroPacketSchemaVersioningTest extends TestCase
{
    public function test_versions_includes_v1_active_and_current_matches_builder_constant(): void
    {
        $registry = new AtlasMaestroPacketSchemaVersioning();
        $versions = $registry->versions();

        $this->assertNotEmpty($versions);
        $v1 = $registry->describe(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1);
        $this->assertNotEmpty($v1);
        $this->assertSame('active', $v1['status']);

        $current = $registry->current();
        $this->assertSame(AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION, $current['id']);
    }

    public function test_supports_and_describe_round_trip(): void
    {
        $registry = new AtlasMaestroPacketSchemaVersioning();
        $this->assertTrue($registry->supports(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1));
        $this->assertFalse($registry->supports('not.a.real.version.v99'));
    }

    public function test_synthetic_v2_makes_latest_advance_but_current_stays_pinned(): void
    {
        $syntheticV2 = [
            'id' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            'version' => 2,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_PREVIEW,
            'required_fields' => ['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'maestro_routing_hint'],
            'additive_fields' => ['maestro_routing_hint'],
            'removed_fields' => [],
            'introduced_at' => '2026-06-25',
            'successor' => null,
        ];

        // currentResolver returns null → defaults to latest active.
        $registry = new AtlasMaestroPacketSchemaVersioning(extraVersions: [$syntheticV2], currentResolver: static fn (): ?string => null);
        $latest = $registry->latest();
        $current = $registry->current();

        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $latest['id']);
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $current['id'], 'current stays on the active v1 by default — operator must flip the pin to advance');

        // Flip the pin → current advances to v2.
        $registryPinned = new AtlasMaestroPacketSchemaVersioning(
            extraVersions: [$syntheticV2],
            currentResolver: static fn (): ?string => 'atlas.self_construction.agent_control_plane_task_packet.v2',
        );
        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $registryPinned->current()['id']);
    }

    public function test_successor_of_resolves(): void
    {
        $syntheticV2 = ['id' => 'v2-id', 'version' => 2, 'status' => 'preview', 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => null];
        $v1WithSuccessor = ['id' => 'v1-bridge', 'version' => 1, 'status' => 'deprecated', 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => 'v2-id'];

        $registry = new AtlasMaestroPacketSchemaVersioning(extraVersions: [$v1WithSuccessor, $syntheticV2]);
        $successor = $registry->successorOf('v1-bridge');
        $this->assertNotNull($successor);
        $this->assertSame('v2-id', $successor['id']);

        $this->assertNull($registry->successorOf(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1));
    }

    public function test_builder_emits_schema_version_sourced_from_registry(): void
    {
        // Reflective check: the builder constant must equal the registry CANONICAL_V1 constant.
        $reflection = new \ReflectionClass(AgentControlPlaneTaskPacketBuilder::class);
        $constant = $reflection->getConstant('SCHEMA_VERSION');
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $constant);
    }
}
