<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleException;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationEvidenceServiceTest extends TestCase
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

    public function test_attach_creates_evidence_ref_and_attached_event(): void
    {
        $factory = app(MissionFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $ref = $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_DOC,
            'evidence_ref' => 'docs/some-doc.md',
            'metadata' => ['note' => 'attached during test'],
        ]);

        $this->assertSame(MissionEvidenceService::TYPE_DOC, $ref->evidence_type);
        $this->assertNotNull($ref->evidence_hash);
        $this->assertSame(['note' => 'attached during test'], $ref->metadata);

        $this->assertSame(
            1,
            $mission->events()->where('event_type', 'evidence.attached')->count(),
            'expected one evidence.attached event',
        );
    }

    public function test_hash_is_deterministic_for_same_reference(): void
    {
        $factory = app(MissionFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $a = $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_RECEIPT,
            'evidence_ref' => 'receipt:abc',
        ]);
        $b = $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_RECEIPT,
            'evidence_ref' => 'receipt:abc',
        ]);

        $this->assertSame($a->evidence_hash, $b->evidence_hash);
    }

    public function test_unknown_evidence_type_is_rejected(): void
    {
        $factory = app(MissionFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $mission = $factory->create('Implementar exporter CSV');

        $this->expectException(MissionLifecycleException::class);
        $evidence->attach($mission, [
            'evidence_type' => 'not_a_real_type',
            'evidence_ref' => 'whatever',
        ]);
    }
}
