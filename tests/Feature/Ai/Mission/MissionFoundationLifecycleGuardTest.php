<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleException;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationLifecycleGuardTest extends TestCase
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

    public function test_invalid_transition_is_rejected(): void
    {
        $factory = app(MissionFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $mission = $factory->create('Trivial: ping?');
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, ['blocker_reason' => 'test']);

        $this->expectException(MissionLifecycleException::class);
        $this->expectExceptionMessageMatches('/Invalid mission lifecycle transition: \[blocked\] -> \[running\]/');
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
    }

    public function test_completion_without_evidence_is_rejected(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $mission = $factory->create('Implementar feature ABC com testes');
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);

        $this->expectException(MissionLifecycleException::class);
        $this->expectExceptionMessageMatches('/without at least one evidence ref/');
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED);
    }

    public function test_completion_without_passed_certification_is_rejected(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $mission = $factory->create('Implementar feature ABC');
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'guard:test:no_certification',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);

        $this->expectException(MissionLifecycleException::class);
        $this->expectExceptionMessageMatches('/without a \[passed\] certification/');
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED);
    }

    public function test_completion_succeeds_after_evidence_and_certification(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $mission = $factory->create('Implementar feature ABC com testes');
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'guard:test:happy_path',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);
        $certification->certify($mission);
        $event = $lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED);

        $this->assertSame(MissionLifecycleService::STATUS_CERTIFYING, $event->status_before);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $event->status_after);
        $this->assertNotNull($mission->refresh()->completed_at);
    }
}
