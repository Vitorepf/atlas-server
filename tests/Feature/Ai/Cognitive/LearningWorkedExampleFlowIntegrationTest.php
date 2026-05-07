<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Domain\AtlasLearningOrchestrator;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningWorkedExampleFlowIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('worked_examples');
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_learning_worked_example_flow_emits_packet_and_receipt(): void
    {
        app(WorkedExampleRepository::class)->create(
            topic: 'pull-request-review',
            domain: 'learning',
            title: 'PR review estrutural',
            problemContext: 'Revisar PR com migration sensivel.',
            solutionFull: [
                ['step' => 1, 'action' => 'Leia diff completo.'],
                ['step' => 2, 'action' => 'Rode rollback.'],
                ['step' => 3, 'action' => 'Agrupe findings.'],
            ],
            source: 'canonical_library',
        );

        $orchestrator = app(AtlasLearningOrchestrator::class);
        $plan = $orchestrator->plan('learning.worked_example', [
            'objective' => 'Aprender review estrutural.',
            'topic' => 'pull-request-review',
            'target_level' => 'can_review_prs',
            'dreyfus_stage_target' => 2,
        ]);
        $result = $orchestrator->execute($plan, ['surface_id' => 'phpunit']);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('worked_example_plan', $plan['mode']);
        $this->assertSame('ok', data_get($plan, 'packet.worked_example.status'));
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('atlas.decide.extension.worked_example.v1', data_get($result, 'result.receipt.metadata.worked_example_request.schema_version'));
        $this->assertContains('WORKED_EXAMPLE_DELIVERED', data_get($result, 'result.ledger.events'));
    }
}
