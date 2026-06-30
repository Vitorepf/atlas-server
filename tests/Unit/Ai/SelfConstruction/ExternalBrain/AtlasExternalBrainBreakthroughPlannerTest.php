<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionEscalationPolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBreakthroughPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBreakthroughPlannerTest extends TestCase
{
    private AtlasExternalBrainBreakthroughPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainBreakthroughPlanner;
    }

    // ── 10-of-100 stall ────────────────────────────────────────────────────────

    public function test_10_of_100_stall_produces_concrete_investigations(): void
    {
        $r = $this->planner->plan([
            'verified_count' => 10,
            'requested_target' => 100,
            'escalation_state' => [],
        ]);

        $this->assertSame(AtlasExternalBrainBreakthroughPlanner::SCHEMA, $r['schema']);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertSame(90, $r['stall_gap']);
        $this->assertNotEmpty($r['investigations'], '10-of-100 stall must produce at least one investigation');

        $inv = $r['investigations'][0];

        // source_categories — must be non-empty strings from the research plan
        $this->assertIsArray($inv['source_categories']);
        $this->assertNotEmpty($inv['source_categories']);
        foreach ($inv['source_categories'] as $cat) {
            $this->assertIsString($cat);
            $this->assertNotEmpty($cat);
        }

        // expected_leverage — must be a non-empty string
        $this->assertIsString($inv['expected_leverage']);
        $this->assertNotEmpty($inv['expected_leverage']);

        // stop_conditions — must be a non-empty list of strings
        $this->assertIsArray($inv['stop_conditions']);
        $this->assertNotEmpty($inv['stop_conditions']);
        foreach ($inv['stop_conditions'] as $cond) {
            $this->assertIsString($cond);
            $this->assertNotEmpty($cond);
        }

        // investigation_id and mode
        $this->assertNotEmpty($inv['investigation_id']);
        $this->assertNotEmpty($inv['mode']);

        // anti_padding_rule — must be a non-empty string naming the rejected proxy signals
        $this->assertArrayHasKey('anti_padding_rule', $inv);
        $this->assertIsString($inv['anti_padding_rule']);
        $this->assertNotEmpty($inv['anti_padding_rule']);

        // next_mode must be a known escalation ladder mode
        $validModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $this->assertContains($r['next_mode'], $validModes);
    }

    // ── honest_exhausted guard ────────────────────────────────────────────────

    public function test_partial_evidence_does_not_set_honest_exhausted(): void
    {
        // 5 of 6 modes attempted with evidence — one mode still lacks evidence.
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
        ];
        $evidenceMap = [];
        foreach ($allModes as $mode) {
            $evidenceMap[$mode] = ['evidence-ref-'.$mode];
        }

        $r = $this->planner->plan([
            'verified_count' => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes' => $allModes,
                'evidence_by_mode' => $evidenceMap,
            ],
        ]);

        $this->assertFalse($r['honest_exhausted'], 'one mode without evidence must not trigger honest_exhausted');
        $this->assertNotEmpty($r['investigations']);
    }

    public function test_all_modes_with_evidence_yields_honest_exhausted(): void
    {
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceMap = [];
        foreach ($allModes as $mode) {
            $evidenceMap[$mode] = ['evidence-ref-'.$mode];
        }

        // All second-pass strategies must also have evidence or denial before honest_exhausted is allowed.
        $secondPassResults = [
            'cross_codebase_scan'    => ['evidence' => ['scan-done'], 'denial_reason' => null],
            'capability_rubric_diff' => ['evidence' => [], 'denial_reason' => 'no near-complete dimensions found'],
            'atlas_journal_harvest'  => ['evidence' => ['journal-ref-1'], 'denial_reason' => null],
        ];

        $r = $this->planner->plan([
            'verified_count'   => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes'    => $allModes,
                'evidence_by_mode'   => $evidenceMap,
                'second_pass_results' => $secondPassResults,
            ],
        ]);

        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame([], $r['investigations']);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    // ── structure contract ────────────────────────────────────────────────────

    public function test_stall_gap_equals_target_minus_verified(): void
    {
        $r = $this->planner->plan([
            'verified_count' => 30,
            'requested_target' => 80,
        ]);

        $this->assertSame(50, $r['stall_gap']);
    }

    public function test_stop_conditions_reference_gap_quota(): void
    {
        $r = $this->planner->plan([
            'verified_count' => 10,
            'requested_target' => 100,
        ]);

        // gap=90 → quota=ceil(45)=45 → stop condition must mention 45
        $conditions = $r['investigations'][0]['stop_conditions'];
        $all = implode(' ', $conditions);
        $this->assertStringContainsString('45', $all, 'stop conditions must encode the gap quota');
    }

    public function test_comparison_questions_are_non_empty_strings(): void
    {
        $r = $this->planner->plan([
            'verified_count' => 0,
            'requested_target' => 50,
        ]);

        $questions = $r['investigations'][0]['comparison_questions'];
        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);
        foreach ($questions as $q) {
            $this->assertIsString($q);
            $this->assertNotEmpty($q);
        }
    }

    public function test_modes_remaining_shrinks_as_modes_are_attempted(): void
    {
        $r1 = $this->planner->plan(['verified_count' => 0, 'requested_target' => 10]);
        $firstMode = $r1['next_mode'];

        $r2 = $this->planner->plan([
            'verified_count' => 0,
            'requested_target' => 10,
            'escalation_state' => [
                'attempted_modes' => [$firstMode],
                'evidence_by_mode' => [$firstMode => ['some-ref']],
            ],
        ]);

        $this->assertNotSame($firstMode, $r2['next_mode'], 'second call must advance to next mode');
        $this->assertNotContains($r2['next_mode'], [$firstMode]);
    }

    // ── AC1: second_pass_strategies in output ─────────────────────────────────

    public function test_plan_includes_second_pass_strategies(): void
    {
        $r = $this->planner->plan(['verified_count' => 5, 'requested_target' => 20]);

        $this->assertArrayHasKey('second_pass_strategies', $r);
        $this->assertNotEmpty($r['second_pass_strategies']);
    }

    public function test_second_pass_strategies_have_required_fields(): void
    {
        $r = $this->planner->plan(['verified_count' => 0, 'requested_target' => 10]);

        foreach ($r['second_pass_strategies'] as $strategy) {
            foreach (['search_surface', 'proof_threshold', 'anti_padding_rule', 'stop_condition'] as $key) {
                $this->assertArrayHasKey($key, $strategy, "second_pass strategy must contain {$key}");
                $this->assertNotEmpty($strategy[$key]);
            }
        }
    }

    public function test_second_pass_strategies_present_in_honest_exhausted_path(): void
    {
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceMap = array_combine($allModes, array_map(fn ($m) => ["ref-{$m}"], $allModes));
        $secondPassResults = [
            'cross_codebase_scan'    => ['evidence' => ['e1'], 'denial_reason' => null],
            'capability_rubric_diff' => ['evidence' => [], 'denial_reason' => 'none found'],
            'atlas_journal_harvest'  => ['evidence' => ['e2'], 'denial_reason' => null],
        ];

        $r = $this->planner->plan([
            'verified_count'   => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes'    => $allModes,
                'evidence_by_mode'   => $evidenceMap,
                'second_pass_results' => $secondPassResults,
            ],
        ]);

        $this->assertTrue($r['honest_exhausted']);
        $this->assertArrayHasKey('second_pass_strategies', $r);
    }

    // ── AC2: honest_exhausted refused without second-pass results ─────────────

    public function test_honest_exhausted_refused_when_second_pass_not_run(): void
    {
        // All 6 modes have evidence, but no second_pass_results → must refuse honest_exhausted.
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceMap = array_combine($allModes, array_map(fn ($m) => ["ref-{$m}"], $allModes));

        $r = $this->planner->plan([
            'verified_count'   => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes'  => $allModes,
                'evidence_by_mode' => $evidenceMap,
                // second_pass_results intentionally absent
            ],
        ]);

        $this->assertFalse($r['honest_exhausted'], 'must refuse honest_exhausted when second-pass strategies have no results');
        $this->assertNotEmpty($r['investigations']);
    }

    public function test_honest_exhausted_refused_when_one_second_pass_strategy_has_no_result(): void
    {
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceMap = array_combine($allModes, array_map(fn ($m) => ["ref-{$m}"], $allModes));

        // Only 2 of 3 second-pass strategies have results.
        $secondPassResults = [
            'cross_codebase_scan'    => ['evidence' => ['e1'], 'denial_reason' => null],
            'capability_rubric_diff' => ['evidence' => [], 'denial_reason' => 'none'],
            // atlas_journal_harvest missing
        ];

        $r = $this->planner->plan([
            'verified_count'   => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes'    => $allModes,
                'evidence_by_mode'   => $evidenceMap,
                'second_pass_results' => $secondPassResults,
            ],
        ]);

        $this->assertFalse($r['honest_exhausted']);
    }

    public function test_denial_reason_alone_satisfies_second_pass_requirement(): void
    {
        $allModes = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceMap = array_combine($allModes, array_map(fn ($m) => ["ref-{$m}"], $allModes));

        // All strategies have denial_reason only (empty evidence).
        $secondPassResults = [
            'cross_codebase_scan'    => ['evidence' => [], 'denial_reason' => 'scan exhausted with nothing found'],
            'capability_rubric_diff' => ['evidence' => [], 'denial_reason' => 'no near-complete dimensions'],
            'atlas_journal_harvest'  => ['evidence' => [], 'denial_reason' => 'journals already processed'],
        ];

        $r = $this->planner->plan([
            'verified_count'   => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes'    => $allModes,
                'evidence_by_mode'   => $evidenceMap,
                'second_pass_results' => $secondPassResults,
            ],
        ]);

        $this->assertTrue($r['honest_exhausted'], 'denial_reason alone must satisfy the second-pass requirement');
    }
}
