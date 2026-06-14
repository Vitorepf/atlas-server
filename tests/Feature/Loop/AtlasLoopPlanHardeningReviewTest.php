<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanHardeningReview;
use Tests\TestCase;

/**
 * PLAN-HARDENING REVIEW — frozen proof of "attack the plan before you spend": a panel finds why the
 * plan would FAIL; the deterministic aggregator decides REPLAN (fatal flaw) / HARDEN (majority risk) /
 * IMPLEMENT (survived). Vague or unknown-node findings cannot block — only specific, grounded ones do.
 */
final class AtlasLoopPlanHardeningReviewTest extends TestCase
{
    private function review(): AtlasLoopPlanHardeningReview
    {
        return new AtlasLoopPlanHardeningReview();
    }

    private array $plan = ['plan_id' => 'obra-x', 'nodes' => [['id' => 'n1'], ['id' => 'n2']]];

    public function test_a_clean_plan_survives_the_attack_and_implements(): void
    {
        $findings = [
            ['lens' => 'decomposition', 'node_id' => 'n1', 'severity' => 'low', 'would_block' => false, 'summary' => 'split looks fine'],
            ['lens' => 'verifiability', 'node_id' => 'n2', 'severity' => 'low', 'would_block' => false, 'summary' => 'acceptance is runnable'],
        ];
        $r = $this->review()->assess($this->plan, $findings, 4);
        $this->assertSame(AtlasLoopPlanHardeningReview::IMPLEMENT, $r['decision']);
    }

    public function test_a_critical_blocking_finding_forces_replan_before_any_spend(): void
    {
        $findings = [
            ['lens' => 'integration', 'node_id' => 'n2', 'severity' => 'critical', 'would_block' => true, 'summary' => 'node n2 deletes the symbol node n1 depends on — the obra cannot integrate'],
        ];
        $r = $this->review()->assess($this->plan, $findings, 4);
        $this->assertSame(AtlasLoopPlanHardeningReview::REPLAN, $r['decision'], 'a fatal flaw is fixed in the plan, never spent on');
        $this->assertCount(1, $r['blocking']);
    }

    public function test_a_majority_flagging_high_risk_forces_harden(): void
    {
        $findings = [
            ['lens' => 'scope_creep', 'node_id' => 'n1', 'severity' => 'high', 'would_block' => false, 'summary' => 'node n1 also rewrites unrelated logic'],
            ['lens' => 'hidden_coupling', 'node_id' => 'n2', 'severity' => 'high', 'would_block' => false, 'summary' => 'node n2 likely needs a file outside scope'],
        ];
        $r = $this->review()->assess($this->plan, $findings, 4); // 2 high >= majority(2)
        $this->assertSame(AtlasLoopPlanHardeningReview::HARDEN, $r['decision']);
        $this->assertSame(2, $r['high_or_critical']);
    }

    public function test_vague_or_unknown_node_findings_are_dropped_not_counted(): void
    {
        $findings = [
            ['lens' => 'x', 'node_id' => 'n1', 'severity' => 'critical', 'would_block' => true, 'summary' => ''], // vague (no summary)
            ['lens' => 'y', 'node_id' => 'ghost', 'severity' => 'critical', 'would_block' => true, 'summary' => 'points at a node that does not exist'],
        ];
        $r = $this->review()->assess($this->plan, $findings, 4);
        $this->assertSame(AtlasLoopPlanHardeningReview::IMPLEMENT, $r['decision'], 'a vague or ungrounded finding cannot block the plan');
        $this->assertSame(2, $r['dropped']);
        $this->assertSame(0, $r['considered']);
    }
}
