<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPlanesAndAuthorityService;
use Tests\TestCase;

/**
 * Pins the six-plane authority boundaries and their documented prohibitions.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/master-architecture/planes-and-authority.md
 */
class AtlasPlanesAndAuthorityTest extends TestCase
{
    private function service(): AtlasPlanesAndAuthorityService
    {
        return new AtlasPlanesAndAuthorityService;
    }

    public function test_control_plane_compiles_but_does_not_perform_domain_work(): void
    {
        $svc = $this->service();

        // Control owns compilation capabilities.
        $owns = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_CONTROL, 'receipt');
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_OWNS, $owns['verdict']);
        $this->assertTrue($owns['owns']);

        // "Control Plane compiles the operation; it does not perform domain work."
        $denied = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_CONTROL, 'semantic_work');
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_DENIED, $denied['verdict']);
        $this->assertSame('control_does_not_perform_domain_work', $denied['reason']);
        $this->assertFalse($denied['owns']);
    }

    public function test_runtime_executes_a_receipt_and_never_chooses_mission_or_policy(): void
    {
        $svc = $this->service();

        // "Runtime executes a receipt." Execution without a receipt is gated.
        $noReceipt = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_RUNTIME, 'execution', false);
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_REQUIRES_RECEIPT, $noReceipt['verdict']);
        $this->assertSame('runtime_executes_a_receipt', $noReceipt['reason']);

        // With a receipt the runtime owns execution.
        $withReceipt = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_RUNTIME, 'execution', true);
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_OWNS, $withReceipt['verdict']);

        // "It never chooses mission or policy."
        $policy = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_RUNTIME, 'policy', true);
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_DENIED, $policy['verdict']);
        $this->assertSame('runtime_never_chooses_policy', $policy['reason']);

        $mission = $svc->runtimeMayExecute(true, true);
        $this->assertFalse($mission['may_execute']);
        $this->assertSame('runtime_never_chooses_mission_or_policy', $mission['reason']);
    }

    public function test_surface_never_decides_provider_domain_tool_or_autonomy(): void
    {
        $svc = $this->service();

        // Surface owns rendering / entry.
        $this->assertSame(
            AtlasPlanesAndAuthorityService::VERDICT_OWNS,
            $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_SURFACE, 'rendering')['verdict'],
        );

        // "Surface never decides provider, domain, tool or autonomy."
        foreach ([
            'provider' => 'surface_never_decides_provider',
            'domain' => 'surface_never_decides_domain',
            'tool' => 'surface_never_decides_tool',
            'autonomy' => 'surface_never_decides_autonomy',
        ] as $capability => $expectedReason) {
            $d = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_SURFACE, $capability);
            $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_DENIED, $d['verdict'], $capability);
            $this->assertSame($expectedReason, $d['reason'], $capability);
        }
    }

    public function test_domain_cannot_create_a_private_pipeline(): void
    {
        $svc = $this->service();

        // Domain owns semantic work, flows, gates.
        $this->assertSame(
            AtlasPlanesAndAuthorityService::VERDICT_OWNS,
            $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_DOMAIN, 'flows')['verdict'],
        );

        // "It cannot create a private pipeline."
        $d = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_DOMAIN, 'private_pipeline');
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_DENIED, $d['verdict']);
        $this->assertSame('domain_cannot_create_private_pipeline', $d['reason']);
    }

    public function test_only_control_plane_chooses_the_allowed_path(): void
    {
        $svc = $this->service();

        // "It chooses allowed path and constraints." — Control only.
        $this->assertTrue($svc->mayChooseAllowedPath(AtlasPlanesAndAuthorityService::PLANE_CONTROL)['may_choose_path']);

        foreach ([
            AtlasPlanesAndAuthorityService::PLANE_DOMAIN,
            AtlasPlanesAndAuthorityService::PLANE_RUNTIME,
            AtlasPlanesAndAuthorityService::PLANE_EVIDENCE,
            AtlasPlanesAndAuthorityService::PLANE_LEARNING,
            AtlasPlanesAndAuthorityService::PLANE_SURFACE,
        ] as $plane) {
            $this->assertFalse(
                $svc->mayChooseAllowedPath($plane)['may_choose_path'],
                $plane.' must not choose the allowed path',
            );
        }

        // allowed_path is owned by Control regardless of who asks.
        $owner = $svc->ownerOf('allowed_path');
        $this->assertSame(AtlasPlanesAndAuthorityService::PLANE_CONTROL, $owner['owner_plane']);
        $this->assertTrue($owner['placed']);
    }

    public function test_ownership_is_exclusive_and_planes_are_a_closed_set(): void
    {
        $svc = $this->service();

        // A capability owned by another plane is refused, not absorbed.
        $crossPlane = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_EVIDENCE, 'execution');
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_DENIED, $crossPlane['verdict']);

        // Unknown plane name -> closed set rejects it.
        $unknownPlane = $svc->authorize('marketing', 'execution', true);
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_UNKNOWN_PLANE, $unknownPlane['verdict']);
        $this->assertSame(AtlasPlanesAndAuthorityService::PLANE_UNKNOWN, $unknownPlane['plane']);

        // Unknown capability not in any plane contract.
        $unknownCap = $svc->authorize(AtlasPlanesAndAuthorityService::PLANE_RUNTIME, 'do_my_taxes', true);
        $this->assertSame(AtlasPlanesAndAuthorityService::VERDICT_UNKNOWN_CAPABILITY, $unknownCap['verdict']);
    }

    public function test_manifest_pins_six_planes_and_invariants(): void
    {
        $m = $this->service()->manifest();

        $this->assertSame(6, $m['plane_count']);
        $this->assertCount(6, $m['planes']);
        $this->assertSame(AtlasPlanesAndAuthorityService::PLANE_CONTROL, $m['path_authority']);
        $this->assertContains('planes_are_authority_boundaries_not_folders_or_ui_sections', $m['invariants']);
        $this->assertContains('runtime_executes_a_receipt', $m['invariants']);

        // Every plane has at least one owned capability.
        foreach ($m['planes'] as $plane) {
            $this->assertNotEmpty($m['owns'][$plane], $plane.' owns at least one capability');
        }
    }
}
