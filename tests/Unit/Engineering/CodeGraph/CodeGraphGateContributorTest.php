<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphGateContributor;
use App\Services\Engineering\CodeGraph\CodeGraphGovernanceSignal;
use Tests\TestCase;

class CodeGraphGateContributorTest extends TestCase
{
    private function contributor(): CodeGraphGateContributor
    {
        return new CodeGraphGateContributor(new CodeGraphGovernanceSignal);
    }

    private function freshStats(int $edges): array
    {
        $now = (int) floor(now()->getTimestamp() / 60);

        return [
            'edge_count' => $edges,
            'last_built_at' => $now,
            'drift_count' => 0,
            'max_age_minutes' => 1440,
            'now_minutes' => $now,
        ];
    }

    public function test_inactive_and_does_not_read_stats_when_flag_off(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        $called = false;
        $result = $this->contributor()->evaluate(function () use (&$called) {
            $called = true;

            return $this->freshStats(0);
        });

        $this->assertSame(CodeGraphGateContributor::STATUS_INACTIVE, $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertFalse($called, 'flag-off path must not even gather stats (inert)');
    }

    public function test_blocked_when_flag_on_and_edges_empty(): void
    {
        config()->set('atlas.code_graph.real_edges', true);

        $result = $this->contributor()->evaluate(fn () => $this->freshStats(0));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_edges_empty', $result['blockers']);
    }

    public function test_ready_when_flag_on_and_graph_fresh_and_populated(): void
    {
        config()->set('atlas.code_graph.real_edges', true);

        $result = $this->contributor()->evaluate(fn () => $this->freshStats(120));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_READY, $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(120, $result['stats']['edge_count']);
    }
}
