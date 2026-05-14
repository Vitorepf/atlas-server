<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService;
use Tests\TestCase;

class AtlasForgeRuntimeCertificationTest extends TestCase
{
    public function test_certify_without_obra_returns_fail_closed_blocker(): void
    {
        $report = app(AtlasForgeRuntimeCertificationService::class)->certify([]);

        $this->assertSame('atlas.forge_runtime_certification.v1', $report['schema_version']);
        $this->assertSame('blocked_missing_obra_binding', $report['forge_core_status']);
        $this->assertSame('blocked_requires_operator_approval', $report['external_rivals_status']);
        $this->assertFalse($report['inputs']['obra_provided']);
        $this->assertContains('missing_obra_binding', $report['remaining_blockers']);

        $stages = collect($report['stages'])->keyBy('name');

        $this->assertSame('passed', $stages['surface_adapter_resolution']['status']);
        $this->assertSame('atlas_code', $stages['surface_adapter_resolution']['surface_id']);
        $this->assertSame(['programming.forge'], $stages['surface_adapter_resolution']['supported_flow_ids']);

        $this->assertSame('blocked', $stages['obra_binding_payload']['status']);
        $this->assertSame('missing_obra_binding', $stages['obra_binding_payload']['blocker']);
        $this->assertSame(
            'atlas.forge_workspace_blocker.v1',
            $stages['obra_binding_payload']['payload']['forge_workspace_blocker']['schema_version'],
        );
    }

    public function test_certify_with_obra_returns_passed_chain(): void
    {
        $obraId = '11111111-1111-1111-1111-111111111111';
        $report = app(AtlasForgeRuntimeCertificationService::class)->certify([
            'obra_id' => $obraId,
        ]);

        $this->assertSame('passed', $report['forge_core_status']);
        $this->assertSame($obraId, $report['inputs']['obra_id']);
        $this->assertTrue($report['inputs']['obra_provided']);
        $this->assertSame([], $report['remaining_blockers']);

        $stages = collect($report['stages'])->keyBy('name');

        $this->assertSame('passed', $stages['surface_adapter_resolution']['status']);

        $this->assertSame('passed', $stages['obra_binding_payload']['status']);
        $this->assertSame($obraId, $stages['obra_binding_payload']['contract']['obra_id']);
        $this->assertSame($obraId, $stages['obra_binding_payload']['contract']['forge_workspace.obra_id']);
        $this->assertSame('obras_shared_workspace', $stages['obra_binding_payload']['contract']['forge_workspace.workspace_kind']);

        $this->assertSame('passed', $stages['domain_catalog_selection']['status']);
        $this->assertSame('programming.forge', $stages['domain_catalog_selection']['flow_id']);
        $this->assertSame('programming', $stages['domain_catalog_selection']['domain_id']);

        $this->assertSame('passed', $stages['governance_route']['status']);
        $this->assertSame('EngineeringHarness', $stages['governance_route']['flow_runtime']);
        $this->assertTrue($stages['governance_route']['destructive_requires_approval']);
        $this->assertTrue($stages['governance_route']['onboarding_ready']);

        $this->assertSame('passed', $stages['evidence_policy']['status']);
        $this->assertSame([], $stages['evidence_policy']['missing_docs']);
    }

    public function test_certify_separates_forge_core_from_external_rivals(): void
    {
        $obraId = '22222222-2222-2222-2222-222222222222';
        $report = app(AtlasForgeRuntimeCertificationService::class)->certify([
            'obra_id' => $obraId,
        ]);

        $this->assertNotSame($report['forge_core_status'], $report['external_rivals_status']);
        $this->assertStringContainsString('Rivals', $report['external_rivals_reason']);
        $this->assertSame('php artisan atlas:forge:runtime-certify --json', $report['e2e_command']);

        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeRuntimeCertificationService.php',
            $report['evidence_paths'],
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeRuntimeCertificationTest.php',
            $report['evidence_paths'],
        );
    }
}
