<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiWorker;
use PHPUnit\Framework\TestCase;

/**
 * C19 — o replanejamento arquiva a versão corrente do plano em plan_revisions[]
 * antes da nova iteração. Função pura: sem DB, sem fabricação.
 */
class PlanRevisionsTest extends TestCase
{
    public function test_replan_archives_current_plan_as_next_revision(): void
    {
        $metadata = [
            'execution_plan' => ['workflow' => 'respond_with_context', 'steps' => [['checkpoint' => 'intent']]],
        ];

        $revisions = AiWorker::planRevisionsAfterReplan($metadata, 1, 'quality_gate_requested_repair', '2026-07-14T14:00:00-03:00');

        $this->assertCount(1, $revisions);
        $this->assertSame(1, $revisions[0]['revision']);
        $this->assertSame(1, $revisions[0]['iteration']);
        $this->assertSame('quality_gate_requested_repair', $revisions[0]['reason']);
        $this->assertSame('2026-07-14T14:00:00-03:00', $revisions[0]['archived_at']);
        $this->assertSame('respond_with_context', $revisions[0]['execution_plan']['workflow']);
    }

    public function test_second_replan_appends_revision_two_preserving_history(): void
    {
        $first = AiWorker::planRevisionsAfterReplan(
            ['execution_plan' => ['workflow' => 'v1']],
            1, 'quality_gate_requested_repair', '2026-07-14T14:00:00-03:00',
        );
        $metadata = ['execution_plan' => ['workflow' => 'v2'], 'plan_revisions' => $first];

        $revisions = AiWorker::planRevisionsAfterReplan($metadata, 2, 'quality_gate_requested_repair', '2026-07-14T15:00:00-03:00');

        $this->assertCount(2, $revisions);
        $this->assertSame('v1', $revisions[0]['execution_plan']['workflow']);
        $this->assertSame(2, $revisions[1]['revision']);
        $this->assertSame('v2', $revisions[1]['execution_plan']['workflow']);
    }

    public function test_no_current_plan_never_fabricates_an_empty_revision(): void
    {
        $prior = [['revision' => 1, 'execution_plan' => ['workflow' => 'v1']]];

        $revisions = AiWorker::planRevisionsAfterReplan(
            ['plan_revisions' => $prior],
            2, 'quality_gate_requested_repair', '2026-07-14T15:00:00-03:00',
        );

        $this->assertSame($prior, $revisions);
    }

    public function test_history_is_capped_at_ten_most_recent(): void
    {
        $revisions = [];
        for ($i = 1; $i <= 12; $i++) {
            $metadata = ['execution_plan' => ['workflow' => "v{$i}"], 'plan_revisions' => $revisions];
            $revisions = AiWorker::planRevisionsAfterReplan($metadata, $i, 'quality_gate_requested_repair', '2026-07-14T15:00:00-03:00');
        }

        $this->assertCount(10, $revisions);
        $this->assertSame('v3', $revisions[0]['execution_plan']['workflow']);
        $this->assertSame('v12', $revisions[9]['execution_plan']['workflow']);
    }
}
