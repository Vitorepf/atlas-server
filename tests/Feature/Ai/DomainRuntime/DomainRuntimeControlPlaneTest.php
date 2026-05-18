<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainHandoffService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeControlPlaneService;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeControlPlaneTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_control_plane_snapshot_aggregates_manifests_runtime_records_and_handoffs(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $registry->seedDefaults(DomainSeedManifests::all());

        $manifest = $registry->findByDomainId('software');
        app(DomainRuntimeRecordService::class)->open($manifest, [
            'selected_capabilities' => ['software.plan_change'],
            'execution_plan' => ['steps' => [['name' => 'plan']]],
        ]);
        app(DomainHandoffService::class)->emit('software', 'research', 'spike investigation', [
            'context_pack' => ['summary' => 'need rfc'],
            'expected_output' => ['research_brief' => true],
            'evidence_refs' => ['evid:1'],
        ]);

        $snapshot = app(DomainRuntimeControlPlaneService::class)->snapshot();

        $this->assertSame(DomainRuntimeControlPlaneService::SCHEMA, $snapshot['schema']);
        $this->assertSame(9, $snapshot['summary']['manifests']);
        $this->assertSame(1, $snapshot['summary']['runtime_records']);
        $this->assertSame(1, $snapshot['summary']['handoffs']);
        $this->assertNotEmpty($snapshot['manifests']);
        $this->assertNotEmpty($snapshot['capabilities']);
    }
}
