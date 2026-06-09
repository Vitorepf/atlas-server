<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyRoleCoverageValidator;
use PHPUnit\Framework\TestCase;

final class ForgeTopologyRoleCoverageValidatorTest extends TestCase
{
    private ForgeTopologyRoleCoverageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForgeTopologyRoleCoverageValidator();
    }

    public function testPlanMissingRepairAgentIsIncoherentAndNamesTheMissingRole(): void
    {
        $result = $this->validator->inspect([
            ['role' => 'primary_builder', 'provider' => 'anthropic', 'status' => 'selected'],
            ['role' => 'critical_reviewer', 'provider' => 'openai', 'status' => 'available'],
            ['role' => 'context_scout', 'provider' => 'google', 'status' => 'available'],
            ['role' => 'local_tool_runner', 'provider' => 'local', 'status' => 'available'],
        ]);

        $this->assertSame('atlas.aaeos.forge_role_coverage.v1', $result['schema_version']);
        $this->assertFalse($result['coherent']);
        $this->assertSame(['repair_agent'], $result['missing_roles']);
        $this->assertContains(
            ['code' => 'missing_canonical_role', 'role' => 'repair_agent'],
            $result['defects'],
        );
    }

    public function testDuplicatePrimaryBuilderReportsDuplicateWhileMissingStillListsFourAbsentRoles(): void
    {
        $result = $this->validator->inspect([
            ['role' => 'primary_builder', 'provider' => 'anthropic', 'status' => 'selected'],
            ['role' => 'primary_builder', 'provider' => 'openai', 'status' => 'available'],
        ]);

        $this->assertSame(['primary_builder'], $result['duplicate_roles']);
        $this->assertContains(
            ['code' => 'duplicate_role_assignment', 'role' => 'primary_builder'],
            $result['defects'],
        );
        $this->assertSame(
            ['critical_reviewer', 'context_scout', 'repair_agent', 'local_tool_runner'],
            $result['missing_roles'],
        );
        $this->assertFalse($result['coherent']);
    }

    public function testAllRolesPresentButNoneSelectedFlagsNoSelectedPrimaryBuilder(): void
    {
        $result = $this->validator->inspect([
            ['role' => 'primary_builder', 'provider' => 'anthropic', 'status' => 'available'],
            ['role' => 'critical_reviewer', 'provider' => 'openai', 'status' => 'available'],
            ['role' => 'context_scout', 'provider' => 'google', 'status' => 'available'],
            ['role' => 'repair_agent', 'provider' => 'anthropic', 'status' => 'available'],
            ['role' => 'local_tool_runner', 'provider' => 'local', 'status' => 'available'],
        ]);

        $this->assertFalse($result['selected_builder_present']);
        $this->assertContains(
            ['code' => 'no_selected_primary_builder', 'role' => 'primary_builder'],
            $result['defects'],
        );
        $this->assertSame([], $result['missing_roles']);
        $this->assertFalse($result['coherent']);
    }

    public function testCompletePlanWithSelectedPrimaryBuilderIsCoherentWithNoDefects(): void
    {
        $result = $this->validator->inspect(array_map(
            static fn (string $role): array => [
                'role' => $role,
                'provider' => $role === AtlasForgeProviderTopologyService::ROLE_LOCAL_TOOL_RUNNER ? 'local' : 'anthropic',
                'status' => $role === AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER ? 'selected' : 'available',
            ],
            AtlasForgeProviderTopologyService::CANONICAL_ROLES,
        ));

        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertSame([], $result['missing_roles']);
        $this->assertSame([], $result['duplicate_roles']);
        $this->assertTrue($result['selected_builder_present']);
    }

    public function testUnknownRoleAddsUnknownDefectButIsNotMissingNorDuplicate(): void
    {
        $result = $this->validator->inspect([
            ['role' => 'primary_builder', 'provider' => 'anthropic', 'status' => 'selected'],
            ['role' => 'critical_reviewer', 'provider' => 'openai', 'status' => 'available'],
            ['role' => 'context_scout', 'provider' => 'google', 'status' => 'available'],
            ['role' => 'repair_agent', 'provider' => 'anthropic', 'status' => 'available'],
            ['role' => 'local_tool_runner', 'provider' => 'local', 'status' => 'available'],
            ['role' => 'mystery_role', 'provider' => 'unknown', 'status' => 'available'],
        ]);

        $this->assertContains(
            ['code' => 'unknown_role_present', 'role' => 'mystery_role'],
            $result['defects'],
        );
        $this->assertNotContains('mystery_role', $result['duplicate_roles']);
        $this->assertNotContains('mystery_role', $result['missing_roles']);
        $this->assertSame([], $result['missing_roles']);
        $this->assertSame([], $result['duplicate_roles']);
        $this->assertFalse($result['coherent']);
    }

    public function testGeneralisesAcrossDifferentMissingDuplicateAndUnknownCombination(): void
    {
        // Inputs deliberately differ from the enumerated cases: a different
        // canonical role (context_scout) is duplicated, two different roles
        // (critical_reviewer, local_tool_runner) are absent, an unknown role
        // is present, and the builder is unselected — exercising every rule
        // simultaneously to defend against canned, input-keyed returns.
        $result = $this->validator->inspect([
            ['role' => 'primary_builder', 'provider' => 'anthropic', 'status' => 'available'],
            ['role' => 'context_scout', 'provider' => 'google', 'status' => 'available'],
            ['role' => 'context_scout', 'provider' => 'openai', 'status' => 'available'],
            ['role' => 'repair_agent', 'provider' => 'anthropic', 'status' => 'available'],
            ['role' => 'phantom_runner', 'provider' => 'unknown', 'status' => 'available'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertSame(['critical_reviewer', 'local_tool_runner'], $result['missing_roles']);
        $this->assertSame(['context_scout'], $result['duplicate_roles']);
        $this->assertFalse($result['selected_builder_present']);
        $this->assertContains(
            ['code' => 'missing_canonical_role', 'role' => 'critical_reviewer'],
            $result['defects'],
        );
        $this->assertContains(
            ['code' => 'missing_canonical_role', 'role' => 'local_tool_runner'],
            $result['defects'],
        );
        $this->assertContains(
            ['code' => 'duplicate_role_assignment', 'role' => 'context_scout'],
            $result['defects'],
        );
        $this->assertContains(
            ['code' => 'no_selected_primary_builder', 'role' => 'primary_builder'],
            $result['defects'],
        );
        $this->assertContains(
            ['code' => 'unknown_role_present', 'role' => 'phantom_runner'],
            $result['defects'],
        );
    }
}
