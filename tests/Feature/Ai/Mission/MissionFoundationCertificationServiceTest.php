<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationCertificationServiceTest extends TestCase
{
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
    }

    protected function tearDown(): void
    {
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_certification_fails_when_no_objectives_and_no_evidence(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Trivial: ping');
        $record = $certification->certify($mission);

        $this->assertSame(MissionCertificationService::STATUS_FAILED, $record->status);
        $missing = collect($record->missing_requirements)->pluck('requirement')->all();
        $this->assertContains('objectives_exist', $missing);
        $this->assertContains('work_orders_exist', $missing);
        $this->assertContains('evidence_refs_exist', $missing);
        $this->assertNull($record->certified_at);
    }

    public function test_certification_passes_when_all_requirements_are_met(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Implementar exporter CSV com testes');
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:happy',
        ]);

        $record = $certification->certify($mission);

        $this->assertSame(MissionCertificationService::STATUS_PASSED, $record->status);
        $this->assertSame([], $record->missing_requirements);
        $this->assertNotNull($record->certified_at);
        $this->assertSame(64, strlen($record->certification_hash));

        $mission->refresh();
        $this->assertSame($record->certification_hash, $mission->certification_hash);
        $this->assertNotNull($mission->evidence_pack_hash);

        $this->assertSame(
            1,
            $mission->events()->where('event_type', 'certification.recorded')->count(),
        );
    }
}
