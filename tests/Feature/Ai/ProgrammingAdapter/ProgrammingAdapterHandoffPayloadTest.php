<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\AtlasForgeHandoffAdapter;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterHandoffPayloadTest extends TestCase
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

    public function test_forge_handoff_carries_context_pack_evidence_and_receipt(): void
    {
        $devAdapter = app(AtlasDevMissionAdapter::class);
        $forge = app(AtlasForgeHandoffAdapter::class);

        $adapted = $devAdapter->adapt('planejar obra grande de migracao multi-modulo com sdd');
        $mission = $adapted['mission'];
        $workOrder = $adapted['work_orders']->first();

        app(MissionEvidenceService::class)->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_DOC,
            'evidence_ref' => 'docs/spec/obra.md',
        ]);

        $handoff = $forge->promote($mission, $workOrder, 'scope_too_large', [
            'capability' => $adapted['capability'],
            'workspace' => 'atlas-server',
        ]);

        $this->assertSame('programming', $handoff->source_domain_id);
        $this->assertSame('programming', $handoff->target_domain_id);
        $this->assertSame(64, strlen((string) $handoff->receipt_hash));
        $this->assertNotEmpty($handoff->context_pack);
        $this->assertArrayHasKey('workspace', $handoff->context_pack);
        $this->assertArrayHasKey('task_contract', $handoff->context_pack);
        $this->assertArrayHasKey('evidence_refs', $handoff->context_pack);
        $this->assertSame('forge_obra_delivery', $handoff->expected_output['kind']);
        $this->assertStringContainsString('forge_escalation', $handoff->reason);
        $this->assertSame('pending', $handoff->status);

        $this->assertGreaterThan(
            0,
            $mission->events()->where('event_type', 'programming.adapter.forge_handoff')->count(),
        );
    }
}
