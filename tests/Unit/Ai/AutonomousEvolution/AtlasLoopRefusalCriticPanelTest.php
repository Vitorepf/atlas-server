<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiGoodhartUnifiedRefusal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRefusalCriticPanel;
use Tests\TestCase;

/**
 * Proves the 3-voter refusal critic panel: a canonical behavior-preserving refactor produces 3 votes
 * (Proxy/Metric/Farm) with the Proxy voter refusing on pattern 'behaviour-preserving-refactor'; any
 * single block-severity vote forces panel.refused=true regardless of the other two; the unified
 * verdict's reasons[] contains EVERY vote's pattern_id (no silent drop).
 */
final class AtlasLoopRefusalCriticPanelTest extends TestCase
{
    public function test_canonical_refactor_only_fixture_yields_three_votes_with_proxy_refusing_on_canonical_pattern_id(): void
    {
        $panel = $this->app->make(AtlasLoopRefusalCriticPanel::class);
        $verdict = $panel->deliberate([
            'revert_recheck_green' => true,
            'red_test_count' => 0,
            'acceptance_contract_delta' => false,
            // not a metric proxy
            'metric_kind' => '',
            // no farm signature
            'wired_proof' => false,
        ]);

        $this->assertCount(3, $verdict['votes'], 'exactly 3 voters (Proxy / MetricProxy / Farm)');

        $byVoter = [];
        foreach ($verdict['votes'] as $v) {
            $byVoter[$v['voter_fqn']] = $v;
        }
        $this->assertArrayHasKey(AtlasLoopRefusalCriticPanel::VOTER_PROXY, $byVoter);
        $this->assertArrayHasKey(AtlasLoopRefusalCriticPanel::VOTER_METRIC, $byVoter);
        $this->assertArrayHasKey(AtlasLoopRefusalCriticPanel::VOTER_FARM, $byVoter);

        $proxy = $byVoter[AtlasLoopRefusalCriticPanel::VOTER_PROXY];
        $this->assertTrue($proxy['refuse'], 'Proxy voter must refuse a behaviour-preserving refactor');
        $this->assertSame('behaviour-preserving-refactor', $proxy['pattern_id']);
    }

    public function test_a_single_block_severity_vote_forces_panel_refused_true_even_if_two_other_voters_allow(): void
    {
        $panel = $this->app->make(AtlasLoopRefusalCriticPanel::class);
        // Trigger MetricProxyLensCritic to vote with severity=block (cyclomatic shrink + zero mutation kills);
        // Proxy and Farm voters allow.
        $verdict = $panel->deliberate([
            // Proxy: NOT a behavior-preserving refactor (red_test_count > 0 ⇒ allow)
            'revert_recheck_green' => false,
            'red_test_count' => 3,
            'acceptance_contract_delta' => false,
            // Metric: cyclomatic shrink + zero kills ⇒ block-severity refuse
            'metric_kind' => 'cyclomatic',
            'metric_delta' => -2.5,
            'mutation_kills' => 0,
            // Farm: clean (wired + production caller)
            'wired_proof' => true,
            'production_caller' => true,
            'characterization_diff' => false,
        ]);

        $proxy = $verdict['votes'][0];
        $metric = $verdict['votes'][1];
        $farm = $verdict['votes'][2];

        $this->assertFalse($proxy['refuse']);
        $this->assertTrue($metric['refuse']);
        $this->assertSame(AtlasLoopRefusalCriticPanel::SEVERITY_BLOCK, $metric['severity']);
        $this->assertFalse($farm['refuse']);
        $this->assertTrue($verdict['refused'], 'any single block-severity vote ⇒ panel.refused=true');
    }

    public function test_unified_refusal_evaluate_carries_every_panel_vote_into_reasons(): void
    {
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::evaluate([
            'revert_recheck_green' => true,
            'red_test_count' => 0,
            'acceptance_contract_delta' => false,
            'metric_kind' => 'cyclomatic',
            'metric_delta' => -1.0,
            'mutation_kills' => 0,
            'wired_proof' => true,
            'production_caller' => false, // farm signature
        ]);

        $patternIds = array_column($verdict->reasons(), 'pattern_id');
        $this->assertContains('behaviour-preserving-refactor', $patternIds);
        $this->assertContains('cyclomatic-proxy', $patternIds);
        $this->assertContains('characterization-test-farm', $patternIds);

        $this->assertTrue($verdict->refused(), 'three load-bearing refusals ⇒ refused=true');
    }

    public function test_unified_refusal_evaluate_accepts_extra_direct_facts_alongside_panel(): void
    {
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::evaluate(
            ['revert_recheck_green' => false, 'red_test_count' => 5, 'metric_kind' => '', 'wired_proof' => false],
            extraFacts: [[
                'source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_CONSTITUTION,
                'pattern_id' => 'forbidden-core-edit',
                'fact' => ['target' => 'AtlasLoopMasterSwitch.php'],
                'severity' => AtlasLoopAntiGoodhartUnifiedRefusal::SEVERITY_CRITICAL,
            ]],
        );

        $patternIds = array_column($verdict->reasons(), 'pattern_id');
        $this->assertContains('forbidden-core-edit', $patternIds, 'direct service-detected fact must merge with panel votes');
    }

    public function test_clean_task_with_no_refusal_signals_yields_refused_false(): void
    {
        $panel = $this->app->make(AtlasLoopRefusalCriticPanel::class);
        $verdict = $panel->deliberate([
            'revert_recheck_green' => false,
            'red_test_count' => 4,
            'acceptance_contract_delta' => true,
            'metric_kind' => '',
            'wired_proof' => true,
            'production_caller' => true,
            'characterization_diff' => false,
            'forbidden_core' => false,
        ]);
        foreach ($verdict['votes'] as $v) {
            $this->assertFalse($v['refuse']);
        }
        $this->assertFalse($verdict['refused']);
    }
}
