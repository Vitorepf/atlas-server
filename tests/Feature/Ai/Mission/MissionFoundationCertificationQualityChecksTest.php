<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationCertificationQualityChecksTest extends TestCase
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

    public function test_run_checks_returns_structured_records_with_id_status_severity_message(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Sample task');

        $checks = $certification->runChecks($mission);
        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('requirement', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('remediation', $check);
            $this->assertArrayHasKey('evidence_refs', $check);
            $this->assertContains(
                $check['status'],
                [
                    MissionCertificationService::CHECK_STATUS_PASSED,
                    MissionCertificationService::CHECK_STATUS_FAILED,
                    MissionCertificationService::CHECK_STATUS_WARN,
                ],
            );
            $this->assertContains(
                $check['severity'],
                [
                    MissionCertificationService::SEVERITY_CRITICAL,
                    MissionCertificationService::SEVERITY_HIGH,
                    MissionCertificationService::SEVERITY_MEDIUM,
                    MissionCertificationService::SEVERITY_LOW,
                ],
            );
        }
    }

    public function test_certification_fails_when_mission_is_empty_with_actionable_message(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Trivial: ping');
        $record = $certification->certify($mission);

        $this->assertSame(MissionCertificationService::STATUS_FAILED, $record->status);
        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_OBJECTIVES_EXIST]['status']);
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_WORK_ORDERS_EXIST]['status']);
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_EVIDENCE_REFS_EXIST]['status']);
        $this->assertNotEmpty($byId[MissionCertificationService::CHECK_EVIDENCE_REFS_EXIST]['remediation']);
    }

    public function test_certification_fails_when_work_order_receipt_hash_is_blank(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create('Mission with tampered work order');
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:hash-tampered',
        ]);

        // Corrupt the work_order's receipt_hash to simulate a broken plan.
        $workOrder = $mission->workOrders()->first();
        AiWorkOrder::query()->where('id', $workOrder->id)->update(['receipt_hash' => null]);

        $record = $certification->certify($mission);
        $this->assertSame(MissionCertificationService::STATUS_FAILED, $record->status);
        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH]['status']);
        $this->assertSame(
            MissionCertificationService::SEVERITY_CRITICAL,
            $byId[MissionCertificationService::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH]['severity'],
        );
        $this->assertStringContainsString(
            'receipt_hash',
            $byId[MissionCertificationService::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH]['message'],
        );
    }

    public function test_certification_fails_when_evidence_ref_has_invalid_hash(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create('Mission with invalid evidence hash');
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:invalid-hash',
        ]);

        // Corrupt the evidence hash.
        $ref = $mission->evidenceRefs()->first();
        AiMissionEvidenceRef::query()->where('id', $ref->id)->update(['evidence_hash' => 'not-a-real-hash']);

        $record = $certification->certify($mission);
        $this->assertSame(MissionCertificationService::STATUS_FAILED, $record->status);
        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_EVIDENCE_REFS_HAVE_VALID_HASH]['status']);
        $this->assertNotEmpty($byId[MissionCertificationService::CHECK_EVIDENCE_REFS_HAVE_VALID_HASH]['evidence_refs']);
    }

    public function test_certification_fails_when_mission_is_blocked(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create('Mission with unresolved blocker');
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:blocked',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, ['blocker_reason' => 'awaiting_external_input']);

        $record = $certification->certify($mission);
        $this->assertSame(MissionCertificationService::STATUS_FAILED, $record->status);

        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $this->assertSame('failed', $byId[MissionCertificationService::CHECK_NO_UNRESOLVED_BLOCKERS]['status']);
        $this->assertStringContainsString('blocked', $byId[MissionCertificationService::CHECK_NO_UNRESOLVED_BLOCKERS]['message']);
        $this->assertStringContainsString('awaiting_external_input', $byId[MissionCertificationService::CHECK_NO_UNRESOLVED_BLOCKERS]['message']);
    }

    public function test_certification_passes_when_minimum_quality_is_met(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Implementar feature mínima com testes');
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:minimum',
        ]);
        $record = $certification->certify($mission);

        $this->assertSame(MissionCertificationService::STATUS_PASSED, $record->status);

        $byId = collect($record->checked_requirements)->keyBy('requirement');
        foreach ([
            MissionCertificationService::CHECK_DOD_HAS_CRITERIA,
            MissionCertificationService::CHECK_OBJECTIVES_EXIST,
            MissionCertificationService::CHECK_WORK_ORDERS_EXIST,
            MissionCertificationService::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH,
            MissionCertificationService::CHECK_EVIDENCE_REFS_EXIST,
            MissionCertificationService::CHECK_EVIDENCE_REFS_HAVE_VALID_TYPE,
            MissionCertificationService::CHECK_EVIDENCE_REFS_HAVE_VALID_HASH,
            MissionCertificationService::CHECK_NO_UNRESOLVED_BLOCKERS,
        ] as $required) {
            $this->assertSame('passed', $byId[$required]['status'], "expected critical check {$required} to pass");
        }
    }

    public function test_explain_returns_actionable_messages_for_failures(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Empty mission to explain');

        $explanation = $certification->explain($certification->runChecks($mission));
        $this->assertNotEmpty($explanation);
        $joined = implode("\n", $explanation);
        $this->assertStringContainsString(MissionCertificationService::CHECK_OBJECTIVES_EXIST, $joined);
        $this->assertStringContainsString(MissionCertificationService::CHECK_EVIDENCE_REFS_EXIST, $joined);
        $this->assertStringContainsString('CRITICAL', $joined);
        $this->assertStringContainsString('remediation', $joined);
    }

    public function test_summarize_counts_passed_failed_warn_and_critical_failed(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Empty mission to summarize');

        $checks = $certification->runChecks($mission);
        $summary = $certification->summarize($checks);
        $this->assertArrayHasKey('passed', $summary);
        $this->assertArrayHasKey('failed', $summary);
        $this->assertArrayHasKey('warn', $summary);
        $this->assertArrayHasKey('critical_failed', $summary);
        $this->assertGreaterThan(0, $summary['failed']);
        $this->assertGreaterThanOrEqual(1, $summary['critical_failed']);
    }

    public function test_certification_warns_when_status_is_not_certifiable_but_still_passes_quality(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        // Mission stays in draft (the happy-path certify-while-draft pattern used
        // by existing tests). Status check should WARN, not fail.
        $mission = $factory->create('Mission certify-while-draft');
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'cert:test:warn-status',
        ]);

        $record = $certification->certify($mission);
        $this->assertSame(MissionCertificationService::STATUS_PASSED, $record->status);
        $statusCheck = collect($record->checked_requirements)
            ->firstWhere('requirement', MissionCertificationService::CHECK_MISSION_STATUS_IS_CERTIFIABLE);
        $this->assertSame('warn', $statusCheck['status']);
        $this->assertStringContainsString('draft', $statusCheck['message']);

        // warn does NOT inflate `missing_requirements`
        $missingRequirements = collect($record->missing_requirements)->pluck('requirement')->all();
        $this->assertNotContains(MissionCertificationService::CHECK_MISSION_STATUS_IS_CERTIFIABLE, $missingRequirements);
    }

    public function test_certification_records_check_summary_in_event_payload(): void
    {
        $factory = app(MissionFactoryService::class);
        $certification = app(MissionCertificationService::class);
        $mission = $factory->create('Mission event payload coverage');
        $record = $certification->certify($mission);

        $event = $mission->events()
            ->where('event_type', 'certification.recorded')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($event);
        $payload = (array) $event->payload;
        $this->assertArrayHasKey('check_summary', $payload);
        $this->assertArrayHasKey('critical_failures', $payload);
        $this->assertSame($record->status, $payload['status']);
    }
}
