<?php

namespace Tests\Feature\Ai\Learning;

use App\Services\Ai\Learning\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Domain\AtlasLearningOrchestrator;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class LearningWorkedExampleFlowIntegrationTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = [
        '2026_05_07_120000_create_dreyfus_overlays_table.php',
        '2026_05_07_140000_create_worked_examples_table.php',
    ];

    private const LEDGER_COMPANION_TABLES = ['worked_examples', 'dreyfus_overlays'];

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
