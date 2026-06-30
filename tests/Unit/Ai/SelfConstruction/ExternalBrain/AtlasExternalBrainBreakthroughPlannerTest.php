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

        $r = $this->planner->plan([
            'verified_count' => 5,
            'requested_target' => 100,
            'escalation_state' => [
                'attempted_modes' => $allModes,
                'evidence_by_mode' => $evidenceMap,
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
}
