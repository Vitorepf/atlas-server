<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionEscalationPolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBreakthroughPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBreakthroughPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainBreakthroughPlanner
    {
        return new AtlasExternalBrainBreakthroughPlanner;
    }

    /** Build escalation_state with evidence for ALL 6 modes (needed for honest_exhausted gate). */
    private function allModesWithEvidence(): array
    {
        $modes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceByMode = [];
        foreach ($modes as $m) {
            $evidenceByMode[$m] = ['concrete_evidence_entry_for_'.$m];
        }

        return [
            'attempted_modes'  => $modes,
            'evidence_by_mode' => $evidenceByMode,
        ];
    }

    /** All 3 second-pass strategies with real evidence + denial. */
    private function allSecondPassSatisfied(): array
    {
        return [
            'cross_codebase_scan'       => ['evidence' => ['orphan_wiring_gap_found']],
            'capability_rubric_diff'     => ['evidence' => ['near_complete_autonomy_dim_missing_one_signal']],
            'atlas_journal_harvest'      => ['denial_reason' => 'no_journal_entries_since_last_harvest'],
        ];
    }

    // ── AC2: stalled quota produces investigation with all required fields ──────

    public function test_stall_produces_at_least_one_investigation(): void
    {
        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 3,
            'escalation_state' => [],
        ]);

        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotEmpty($r['investigations']);
    }

    public function test_investigation_has_mode_field(): void
    {
        $r = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 5]);

        $inv = $r['investigations'][0];
        $this->assertArrayHasKey('mode', $inv);
        $this->assertNotEmpty($inv['mode']);
    }

    public function test_investigation_has_source_categories(): void
    {
        $r   = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 3]);
        $inv = $r['investigations'][0];

        $this->assertArrayHasKey('source_categories', $inv);
        $this->assertIsArray($inv['source_categories']);
        $this->assertNotEmpty($inv['source_categories']);
    }

    public function test_investigation_has_comparison_questions(): void
    {
        $r   = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 3]);
        $inv = $r['investigations'][0];

        $this->assertArrayHasKey('comparison_questions', $inv);
        $this->assertIsArray($inv['comparison_questions']);
        $this->assertNotEmpty($inv['comparison_questions']);
    }

    public function test_investigation_stop_conditions_tied_to_verified_evidence_gain(): void
    {
        $r   = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 4]);
        $inv = $r['investigations'][0];

        $this->assertArrayHasKey('stop_conditions', $inv);
        $this->assertNotEmpty($inv['stop_conditions']);

        // At least one stop condition must reference verified count increase.
        $combined = implode('|', $inv['stop_conditions']);
        $this->assertStringContainsString('verified', $combined);
    }

    // ── AC3: refuse honest_exhausted without all second-pass strategies satisfied ─

    public function test_refuses_honest_exhausted_when_second_pass_strategies_missing(): void
    {
        // All 6 modes have evidence but second_pass_results is empty.
        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 1,
            'escalation_state' => array_merge($this->allModesWithEvidence(), [
                'second_pass_results' => [],
            ]),
        ]);

        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotEmpty($r['investigations']);
    }

    public function test_refuses_honest_exhausted_when_one_strategy_has_no_evidence_or_denial(): void
    {
        $secondPass = $this->allSecondPassSatisfied();
        // Remove denial/evidence from one strategy.
        $secondPass['atlas_journal_harvest'] = [];

        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 1,
            'escalation_state' => array_merge($this->allModesWithEvidence(), [
                'second_pass_results' => $secondPass,
            ]),
        ]);

        $this->assertFalse($r['honest_exhausted']);
    }

    public function test_grants_honest_exhausted_when_all_second_pass_strategies_satisfied(): void
    {
        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 1,
            'escalation_state' => array_merge($this->allModesWithEvidence(), [
                'second_pass_results' => $this->allSecondPassSatisfied(),
            ]),
        ]);

        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame([], $r['investigations']);
    }

    // ── AC4: proxy evidence (queue/test/template) must not count ─────────────

    public function test_proxy_only_evidence_prevents_honest_exhausted(): void
    {
        // All 3 second-pass strategies provide only proxy evidence.
        $secondPass = [
            'cross_codebase_scan'   => ['evidence' => ['queue_task_count:5']],
            'capability_rubric_diff' => ['evidence' => ['test_count:10']],
            'atlas_journal_harvest'  => ['evidence' => ['template_volume:3']],
        ];

        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 1,
            'escalation_state' => array_merge($this->allModesWithEvidence(), [
                'second_pass_results' => $secondPass,
            ]),
        ]);

        // Proxy evidence must NOT satisfy the second-pass requirement.
        $this->assertFalse($r['honest_exhausted']);
    }

    public function test_mixed_real_and_proxy_evidence_passes_when_at_least_one_real(): void
    {
        $secondPass = $this->allSecondPassSatisfied();
        // Add proxy alongside real evidence in one strategy — should still pass.
        $secondPass['cross_codebase_scan']['evidence'][] = 'queue_task_count:99';

        $r = $this->planner()->plan([
            'verified_count'   => 0,
            'requested_target' => 1,
            'escalation_state' => array_merge($this->allModesWithEvidence(), [
                'second_pass_results' => $secondPass,
            ]),
        ]);

        $this->assertTrue($r['honest_exhausted']);
    }

    public function test_second_pass_strategies_listed_in_output(): void
    {
        $r = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 3]);

        $this->assertArrayHasKey('second_pass_strategies', $r);
        $this->assertCount(3, $r['second_pass_strategies']);
    }

    public function test_anti_padding_rule_present_in_each_strategy(): void
    {
        $r = $this->planner()->plan(['verified_count' => 0, 'requested_target' => 3]);

        foreach ($r['second_pass_strategies'] as $strategy) {
            $this->assertArrayHasKey('anti_padding_rule', $strategy);
            $this->assertNotEmpty($strategy['anti_padding_rule']);
        }
    }
}
