<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingEvidenceBridge;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterEvidenceBridgeTest extends TestCase
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

    public function test_evidence_bridge_attaches_to_mission_and_falls_back_for_evidence_runtime(): void
    {
        $adapted = app(AtlasDevMissionAdapter::class)->adapt('rodar suite de testes phpunit');
        $workOrder = $adapted['work_orders']->first();

        $result = app(ProgrammingEvidenceBridge::class)->attach(
            $adapted['mission'],
            MissionEvidenceService::TYPE_TEST,
            'tests:phpunit:smoke',
            ['scope' => 'evidence_bridge_test'],
            $workOrder,
        );

        $this->assertNotNull($result['mission_evidence_ref_id']);
        $this->assertSame(64, strlen((string) $result['mission_evidence_hash']));
        $this->assertNull($result['mission_evidence_error']);
        $this->assertSame('programming_adapter_local_receipt', $result['evidence_runtime']['kind']);
        $this->assertSame('evidence_runtime_unavailable', $result['evidence_runtime']['reason']);

        $this->assertSame(
            1,
            $adapted['mission']->evidenceRefs()->where('evidence_type', 'test')->count(),
        );
    }
}
