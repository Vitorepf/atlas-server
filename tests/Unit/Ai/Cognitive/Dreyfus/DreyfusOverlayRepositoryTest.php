<?php

namespace Tests\Unit\Ai\Cognitive\Dreyfus;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DreyfusOverlayRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dreyfus_overlays');

        parent::tearDown();
    }

    public function test_repository_upserts_and_reads_overlay(): void
    {
        $repository = app(DreyfusOverlayRepository::class);
        $nodeId = $repository->nodeIdForTopic('Laravel Queues');

        $overlay = $repository->upsert(
            knowledgeNodeId: $nodeId,
            domain: 'programming',
            level: 4,
            confidence: 0.87,
            evidenceRefs: ['PROG_TRACE_1', 'MASTERY_1'],
            lastUpdatedVia: 'mastery_evidence_aggregator',
            specialistProfile: 'programming.backend_api',
        );

        $this->assertSame('atlas.cognitive.dreyfus_overlay.v1', $overlay['schema_version']);
        $this->assertSame('ok', $overlay['status']);
        $this->assertSame($nodeId, $overlay['knowledge_node_id']);
        $this->assertSame('programming', $overlay['domain']);
        $this->assertSame('programming.backend_api', $overlay['specialist_profile']);
        $this->assertSame(4, $overlay['current_level']);
        $this->assertSame(0.87, $overlay['confidence']);
        $this->assertSame(['PROG_TRACE_1', 'MASTERY_1'], $overlay['evidence_refs']);

        $this->assertSame($overlay['knowledge_node_id'], data_get($repository->find($nodeId, 'programming'), 'knowledge_node_id'));
    }
}
