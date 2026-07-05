<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationDodCoverageMatcherTest extends TestCase
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

    public function test_disjoint_dod_criterion_is_reported_as_uncovered(): void
    {
        // Build a mission with two token-disjoint DoD criteria and a single
        // evidence_ref whose payload matches only the first.
        $objectiveText = 'Exporter CSV com testes de cobertura de dados';
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create($objectiveText, [
            'definition_of_done' => [
                'criteria' => [
                    'CSV export handles malformed rows without any data corruption',
                    'Zigbee coordinator firmware upgrade completes within thirty seconds',
                ],
            ],
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);

        // Attach ONE evidence_ref whose payload token-overlaps ONLY the first criterion.
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'test:csv-corruption-handling',
        ]);

        $record = $certification->certify($mission);

        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $coverageCheck = $byId[MissionCertificationService::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE];

        // Must report covered_criteria_count=1 and list the second criterion as uncovered
        $this->assertSame('warn', $coverageCheck['status']);
        $this->assertArrayHasKey('coverage', $coverageCheck);

        $coverage = $coverageCheck['coverage'];
        $this->assertSame(1, $coverage['covered_criteria_count']);

        // uncovered_criteria must list the Zigbee criterion
        $this->assertCount(1, $coverage['uncovered_criteria']);
        $this->assertStringContainsString('Zigbee', $coverage['uncovered_criteria'][0]);

        // The covered criterion must map to the CORRECT evidence_ref
        $this->assertCount(1, $coverage['criterion_evidence_map']);
        $firstMap = $coverage['criterion_evidence_map'][0];
        $this->assertStringContainsString('CSV', $firstMap['criterion']);
        $this->assertArrayHasKey('evidence_ref_id', $firstMap);
        $this->assertArrayHasKey('evidence_ref_index', $firstMap);
    }

    public function test_hash_differs_when_different_criterion_is_covered(): void
    {
        // Certify once with an evidence_ref that matches the first criterion,
        // then again with the second criterion — the certification_hash must differ.
        $objectiveText = 'Exporter CSV com testes de cobertura de dados';
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create($objectiveText, [
            'definition_of_done' => [
                'criteria' => [
                    'CSV export handles malformed rows without crashing',
                    'Redis stream processor handles backpressure without memory growth',
                ],
            ],
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);

        // First cert: evidence matches only the CSV criterion
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'test:csv-handles-malformed-rows',
        ]);
        $firstRecord = $certification->certify($mission);
        $firstHash = $firstRecord->certification_hash;

        // Detach the old evidence, attach new evidence matching the SECOND criterion
        $mission->evidenceRefs()->delete();
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'test:redis-backpressure-handled',
        ]);
        $secondRecord = $certification->certify($mission);
        $secondHash = $secondRecord->certification_hash;

        // The two certification_hashes must differ because the coverage check
        // result (covered_criteria_count, uncovered_criteria, criterion_evidence_map)
        // enters the SHA-256 certification_hash via checked_requirements.
        $this->assertNotSame($firstHash, $secondHash);
        $this->assertSame(64, strlen($firstHash));
        $this->assertSame(64, strlen($secondHash));
    }

    public function test_both_criteria_covered_by_different_evidence_refs(): void
    {
        $objectiveText = 'Exporter CSV com testes de cobertura de dados';
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);

        $mission = $factory->create($objectiveText, [
            'definition_of_done' => [
                'criteria' => [
                    'CSV export handles malformed rows without crashing',
                    'Redis stream processor handles backpressure without memory growth',
                ],
            ],
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);

        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'test:csv-handles-malformed-rows',
        ]);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'test:redis-backpressure-handled',
        ]);

        $record = $certification->certify($mission);

        $byId = collect($record->checked_requirements)->keyBy('requirement');
        $coverageCheck = $byId[MissionCertificationService::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE];

        $this->assertSame('passed', $coverageCheck['status']);
        $this->assertArrayHasKey('coverage', $coverageCheck);
        $this->assertSame(2, $coverageCheck['coverage']['covered_criteria_count']);
        $this->assertCount(0, $coverageCheck['coverage']['uncovered_criteria']);
        $this->assertCount(2, $coverageCheck['coverage']['criterion_evidence_map']);
    }
}
