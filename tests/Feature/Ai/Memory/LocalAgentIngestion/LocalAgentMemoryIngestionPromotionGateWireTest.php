<?php

namespace Tests\Feature\Ai\Memory\LocalAgentIngestion;

use App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentMemoryIngestionCanon;
use App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentMemoryIngestionService;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesLocalAgentIngestionTables;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: every candidate payload emitted by run() now carries the
 * advisory `memory_promotion_gate` verdict computed from the candidate's own
 * source signals. Candidate status / memory_eligible are byte-identical to
 * before (still hard-quarantined) — the gate only records WHY promotion is
 * blocked and which evidence is missing.
 */
class LocalAgentMemoryIngestionPromotionGateWireTest extends TestCase
{
    use CreatesLocalAgentIngestionTables;

    private string $fixtureRoot;

    private LocalAgentMemoryIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLocalAgentIngestionTables();
        $this->fixtureRoot = sys_get_temp_dir().'/atlas-lai-gate-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->fixtureRoot, recursive: true, force: true);
        $this->service = app(LocalAgentMemoryIngestionService::class);
        config(['atlas_local_agent_ingestion.dry_run_default' => false]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureRoot)) {
            File::deleteDirectory($this->fixtureRoot);
        }
        $this->dropLocalAgentIngestionTables();
        parent::tearDown();
    }

    public function test_candidate_payload_carries_a_coherent_promotion_gate_verdict(): void
    {
        File::put(
            $this->fixtureRoot.'/plan.md',
            "# Plan\n\nImplement provider router refactor and verify with make test.\n",
        );
        config(['atlas_local_agent_ingestion.roots' => [
            ['alias' => 'fixture', 'path' => $this->fixtureRoot, 'enabled' => true],
        ]]);

        $result = $this->service->run();

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];

        // Pre-existing verdict byte-identical: still hard-quarantined.
        $this->assertSame(LocalAgentMemoryIngestionCanon::CANDIDATE_STATUS_QUARANTINED, $candidate['status']);
        $this->assertFalse($candidate['memory_eligible']);

        $gate = $candidate['payload']['memory_promotion_gate'];
        $this->assertIsArray($gate);
        $this->assertSame('atlas.local_agent.memory_promotion_gate.v1', $gate['schema_version']);
        // memory_eligible is hardcoded false today, so the gate must block on
        // exactly that (plus confidence when quality is low) — never on the
        // signals this candidate actually carries.
        $this->assertSame('block', $gate['verdict']);
        $this->assertFalse($gate['promote_allowed']);
        $this->assertContains('memory_not_eligible', $gate['blockers']);
        $this->assertNotContains('classification_missing', $gate['blockers']);
        $this->assertNotContains('lineage_missing', $gate['blockers']);
        $this->assertNotContains('secret_scan_failed', $gate['blockers']);
        $this->assertNotContains('confidence_out_of_range', $gate['blockers']);
        // Property: confidence is the source quality_score mapped onto 0..1.
        $this->assertSame(
            round(((int) $candidate['payload']['quality_score']) / 100, 4),
            $gate['confidence'],
        );
        $this->assertGreaterThanOrEqual(0.0, $gate['confidence']);
        $this->assertLessThanOrEqual(1.0, $gate['confidence']);
    }
}
