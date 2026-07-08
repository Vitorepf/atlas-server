<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaDeprecationGate;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\DeprecatedSchemaRefusedException;
use Tests\TestCase;

final class AtlasMaestroPacketSchemaDeprecationGateTest extends TestCase
{
    protected function tearDown(): void
    {
        AtlasMaestroPacketSchemaDeprecationGate::reset();
        parent::tearDown();
    }

    private function deprecateV1Registry(): void
    {
        $v1Deprecated = [
            'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'version' => 1,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2025-12-01',
            'successor' => null,
        ];
        AtlasMaestroPacketSchemaDeprecationGate::$registryOverride = new AtlasMaestroPacketSchemaVersioning(
            extraVersions: [$v1Deprecated],
        );
    }

    private function packet(): array
    {
        return ['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1];
    }

    public function test_assert_serveable_throws_on_deprecated_packet_without_override(): void
    {
        $this->deprecateV1Registry();
        $this->expectException(DeprecatedSchemaRefusedException::class);
        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($this->packet());
    }

    public function test_valid_unexpired_scoped_override_token_allows_serving(): void
    {
        $this->deprecateV1Registry();
        $token = 'OPERATOR-SECRET';
        AtlasMaestroPacketSchemaDeprecationGate::$overrideConfigResolver = static fn (): array => [[
            'token_hash' => hash('sha256', $token),
            'allowed_versions' => [AtlasMaestroPacketSchemaVersioning::CANONICAL_V1],
            'expires_at' => time() + 3600,
            'reason' => 'transitional override',
        ]];

        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($this->packet(), $token);
        $this->assertTrue(true, 'valid override token must allow void return');
    }

    public function test_expired_override_token_is_rejected(): void
    {
        $this->deprecateV1Registry();
        $token = 'OPERATOR-SECRET';
        AtlasMaestroPacketSchemaDeprecationGate::$overrideConfigResolver = static fn (): array => [[
            'token_hash' => hash('sha256', $token),
            'allowed_versions' => [AtlasMaestroPacketSchemaVersioning::CANONICAL_V1],
            'expires_at' => time() - 60, // expired
            'reason' => 'past',
        ]];

        $this->expectException(DeprecatedSchemaRefusedException::class);
        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($this->packet(), $token);
    }

    public function test_wrong_version_scoped_override_token_is_rejected(): void
    {
        $this->deprecateV1Registry();
        $token = 'OPERATOR-SECRET';
        AtlasMaestroPacketSchemaDeprecationGate::$overrideConfigResolver = static fn (): array => [[
            'token_hash' => hash('sha256', $token),
            'allowed_versions' => ['some.other.version.v9'],
            'expires_at' => time() + 3600,
            'reason' => 'scoped to wrong version',
        ]];

        $this->expectException(DeprecatedSchemaRefusedException::class);
        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($this->packet(), $token);
    }

    public function test_builder_build_surfaces_exception_when_active_version_is_forcibly_deprecated(): void
    {
        $this->deprecateV1Registry();
        $builder = new AgentControlPlaneTaskPacketBuilder();

        $this->expectException(DeprecatedSchemaRefusedException::class);
        $builder->build([
            'objective' => 'Demo objective for deprecation test',
            'allowed_files' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_criteria' => ['phpunit green'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);
    }

    public function test_should_warn_returns_true_for_preview_status(): void
    {
        $previewV2 = [
            'id' => 'atlas.preview.v2',
            'version' => 2,
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_PREVIEW,
            'required_fields' => [],
            'additive_fields' => [],
            'removed_fields' => [],
            'introduced_at' => '2026-06-25',
            'successor' => null,
        ];
        AtlasMaestroPacketSchemaDeprecationGate::$registryOverride = new AtlasMaestroPacketSchemaVersioning(extraVersions: [$previewV2]);
        $this->assertTrue(AtlasMaestroPacketSchemaDeprecationGate::shouldWarn(['schema_version' => 'atlas.preview.v2']));
        $this->assertFalse(AtlasMaestroPacketSchemaDeprecationGate::shouldWarn(['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1]));
    }

    public function test_check_returns_servable_false_with_named_blockers_for_deprecated_schema_without_lossless_upgrade(): void
    {
        $this->deprecateV1Registry(); // successor=null → no lossless upgrade path

        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertFalse($verdict['servable']);
        $this->assertNotEmpty($verdict['blockers']);
        $this->assertStringContainsString(
            'deprecated_schema_no_lossless_upgrade_proof:'.AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            implode(',', $verdict['blockers']),
        );
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, $verdict['schema_status']);
    }

    public function test_check_returns_servable_true_for_current_active_schema(): void
    {
        // No registry override: CANONICAL_V1 is active by default.
        $verdict = AtlasMaestroPacketSchemaDeprecationGate::check($this->packet());

        $this->assertTrue($verdict['servable']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE, $verdict['schema_status']);
    }

    // ── checkRemoval: removal_allowed, live_reference_count, blocking_fields, migration_evidence_required ──

    public function test_check_removal_has_required_keys(): void
    {
        $result = AtlasMaestroPacketSchemaDeprecationGate::checkRemoval([
            'field_name' => 'old_field',
            'live_packets' => [],
            'migration_evidence' => ['migrated_to_new_field'],
        ]);
        $this->assertArrayHasKey('removal_allowed', $result);
        $this->assertArrayHasKey('live_reference_count', $result);
        $this->assertArrayHasKey('blocking_fields', $result);
        $this->assertArrayHasKey('migration_evidence_required', $result);
    }

    public function test_removal_blocked_when_live_packets_reference_field(): void
    {
        $result = AtlasMaestroPacketSchemaDeprecationGate::checkRemoval([
            'field_name' => 'old_field',
            'live_packets' => [
                ['task_packet_id' => 'pkt-1', 'queue_status' => 'queued', 'old_field' => 'value'],
                ['task_packet_id' => 'pkt-2', 'queue_status' => 'claimed', 'objective' => 'uses old_field'],
            ],
            'migration_evidence' => ['migrated_to_new_field'],
        ]);
        $this->assertFalse($result['removal_allowed']);
        $this->assertSame(2, $result['live_reference_count']);
        $this->assertTrue($result['migration_evidence_required']);
        $this->assertContains('pkt-1', $result['blocking_fields']);
        $this->assertContains('pkt-2', $result['blocking_fields']);
    }

    public function test_removal_allowed_when_zero_live_references_and_evidence_present(): void
    {
        $result = AtlasMaestroPacketSchemaDeprecationGate::checkRemoval([
            'field_name' => 'old_field',
            'live_packets' => [
                ['task_packet_id' => 'pkt-1', 'queue_status' => 'delivered'], // not live
            ],
            'migration_evidence' => ['migrated_to_new_field'],
        ]);
        $this->assertTrue($result['removal_allowed']);
        $this->assertSame(0, $result['live_reference_count']);
        $this->assertFalse($result['migration_evidence_required']);
    }

    public function test_removal_blocked_when_no_migration_evidence(): void
    {
        $result = AtlasMaestroPacketSchemaDeprecationGate::checkRemoval([
            'field_name' => 'old_field',
            'live_packets' => [],
            'migration_evidence' => [],
        ]);
        $this->assertFalse($result['removal_allowed']);
        $this->assertSame(0, $result['live_reference_count']);
    }

    public function test_non_live_packets_do_not_block_removal(): void
    {
        $result = AtlasMaestroPacketSchemaDeprecationGate::checkRemoval([
            'field_name' => 'old_field',
            'live_packets' => [
                ['task_packet_id' => 'pkt-1', 'queue_status' => 'delivered', 'old_field' => 'value'],
                ['task_packet_id' => 'pkt-2', 'queue_status' => 'retired', 'old_field' => 'value'],
            ],
            'migration_evidence' => ['migrated'],
        ]);
        $this->assertTrue($result['removal_allowed']);
        $this->assertSame(0, $result['live_reference_count']);
    }
}
