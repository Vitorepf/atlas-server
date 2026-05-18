<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\AtlasForgeHandoffAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingControlPlaneProjection;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainKernelCanon;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainRuntimeAdapter;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterControlPlaneTest extends TestCase
{
    use CreatesProgrammingAdapterTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgrammingAdapterTables();
    }

    protected function tearDown(): void
    {
        $this->dropProgrammingAdapterTables();
        parent::tearDown();
    }

    public function test_control_plane_projects_programming_state_with_handoff_count(): void
    {
        $dev = app(AtlasDevMissionAdapter::class);
        $runtime = app(ProgrammingDomainRuntimeAdapter::class);
        $forge = app(AtlasForgeHandoffAdapter::class);

        $devAdapted = $dev->adapt('corrigir typo no readme');
        $runtime->plan($devAdapted['mission'], $devAdapted['work_orders']->first(), ['capability' => $devAdapted['capability']]);

        $forgeAdapted = $dev->adapt('planejar obra grande multi-modulo com sdd e multiagente');
        $forge->promote($forgeAdapted['mission'], $forgeAdapted['work_orders']->first(), 'scope_too_large');

        $snapshot = app(ProgrammingControlPlaneProjection::class)->snapshot();

        $this->assertSame(ProgrammingControlPlaneProjection::SCHEMA, $snapshot['schema']);
        $this->assertSame(ProgrammingDomainKernelCanon::DOMAIN_ID, $snapshot['domain_id']);
        $this->assertSame(2, $snapshot['programming']['missions_total']);
        $this->assertSame(1, $snapshot['programming']['dev_to_forge_escalations']);
        $this->assertGreaterThanOrEqual(2, $snapshot['programming']['total_work_orders']);
        $this->assertArrayHasKey('policy_runtime_available', $snapshot['bridges']);
        $this->assertSame(ProgrammingDomainKernelCanon::CAPABILITIES, $snapshot['capabilities']);
    }
}
