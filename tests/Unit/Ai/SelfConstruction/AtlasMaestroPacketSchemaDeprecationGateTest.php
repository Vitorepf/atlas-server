<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
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
}
