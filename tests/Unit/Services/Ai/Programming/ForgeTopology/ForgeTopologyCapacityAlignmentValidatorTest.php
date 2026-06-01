<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyCapacityAlignmentValidator;
use PHPUnit\Framework\TestCase;

final class ForgeTopologyCapacityAlignmentValidatorTest extends TestCase
{
    private ForgeTopologyCapacityAlignmentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForgeTopologyCapacityAlignmentValidator();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->validator->inspect([], []);

        $this->assertSame('atlas.aaeos.forge_capacity_alignment.v1', $result['schema_version']);
        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertSame([], $result['orphan_providers']);
        $this->assertSame([], $result['exhausted_selected']);
    }

    public function testRoleProviderAbsentFromCapacityIsOrphanWithDefect(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'planner', 'provider' => 'codex_cli', 'status' => 'selected'],
            ],
            [
                ['provider' => 'claude_code', 'capacity_state' => 'available'],
            ],
        );

        $this->assertFalse($result['coherent']);
        $this->assertContains('codex_cli', $result['orphan_providers']);
        $this->assertSame(
            [
                ['code' => 'role_provider_absent_from_capacity', 'role' => 'planner', 'provider' => 'codex_cli'],
            ],
            $result['defects'],
        );
        $this->assertSame([], $result['exhausted_selected']);
    }

    public function testSelectedRoleOnUnavailableProviderIsExhaustedAndAvailableSiblingIsNot(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'builder', 'provider' => 'codex_cli', 'status' => 'selected'],
                ['role' => 'reviewer', 'provider' => 'codex_cli', 'status' => 'available'],
            ],
            [
                ['provider' => 'codex_cli', 'capacity_state' => 'unavailable'],
            ],
        );

        $this->assertFalse($result['coherent']);
        $this->assertSame(['builder'], $result['exhausted_selected']);
        $this->assertNotContains('reviewer', $result['exhausted_selected']);
        $this->assertSame(
            [
                ['code' => 'selected_role_on_exhausted_capacity', 'role' => 'builder', 'provider' => 'codex_cli'],
            ],
            $result['defects'],
        );
        $this->assertSame([], $result['orphan_providers']);
    }

    public function testFallbackSelectedRoleOnUnavailableProviderIsFlagged(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'builder', 'provider' => 'codex_cli', 'status' => 'fallback_selected'],
            ],
            [
                ['provider' => 'codex_cli', 'capacity_state' => 'unavailable'],
            ],
        );

        $this->assertFalse($result['coherent']);
        $this->assertSame(['builder'], $result['exhausted_selected']);
        $this->assertSame(
            [
                ['code' => 'selected_role_on_exhausted_capacity', 'role' => 'builder', 'provider' => 'codex_cli'],
            ],
            $result['defects'],
        );
    }

    public function testAllRolesOnPresentNonUnavailableProvidersAreCoherent(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'planner', 'provider' => 'claude_code', 'status' => 'selected'],
                ['role' => 'builder', 'provider' => 'codex_cli', 'status' => 'fallback_selected'],
                ['role' => 'reviewer', 'provider' => 'gemini', 'status' => 'available'],
            ],
            [
                ['provider' => 'claude_code', 'capacity_state' => 'available'],
                ['provider' => 'codex_cli', 'capacity_state' => 'degraded'],
                ['provider' => 'gemini', 'capacity_state' => 'available'],
            ],
        );

        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertSame([], $result['orphan_providers']);
        $this->assertSame([], $result['exhausted_selected']);
    }

    public function testRoleWithBlankProviderIsSkipped(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'ghost', 'provider' => '', 'status' => 'selected'],
                ['role' => 'phantom', 'status' => 'selected'],
            ],
            [
                ['provider' => 'claude_code', 'capacity_state' => 'available'],
            ],
        );

        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertSame([], $result['orphan_providers']);
        $this->assertSame([], $result['exhausted_selected']);
    }

    public function testCapacityIndexIsBuiltFromInputNotHardCoded(): void
    {
        $result = $this->validator->inspect(
            [
                ['role' => 'planner', 'provider' => 'minimax_self_host', 'status' => 'selected'],
            ],
            [
                ['provider' => 'minimax_self_host', 'capacity_state' => 'unavailable'],
            ],
        );

        $this->assertFalse($result['coherent']);
        $this->assertSame(['planner'], $result['exhausted_selected']);
        $this->assertSame(
            [
                ['code' => 'selected_role_on_exhausted_capacity', 'role' => 'planner', 'provider' => 'minimax_self_host'],
            ],
            $result['defects'],
        );
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $roles = [
            ['role' => 'planner', 'provider' => 'codex_cli', 'status' => 'selected'],
            ['role' => 'builder', 'provider' => 'gemini', 'status' => 'available'],
        ];
        $providerCapacity = [
            ['provider' => 'codex_cli', 'capacity_state' => 'unavailable'],
            ['provider' => 'gemini', 'capacity_state' => 'degraded'],
        ];

        $first = $this->validator->inspect($roles, $providerCapacity);
        $second = $this->validator->inspect($roles, $providerCapacity);

        $this->assertSame($first, $second);
    }
}
