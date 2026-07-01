<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionControlPlane;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionControlPlaneTest extends TestCase
{
    private function controlPlane(): AtlasExternalBrainCompressionControlPlane
    {
        return new AtlasExternalBrainCompressionControlPlane;
    }

    public function test_exposes_all_required_output_fields(): void
    {
        $r = $this->controlPlane()->evaluate(['candidates' => []]);

        foreach (['schema', 'readiness', 'top_hotspots', 'safe_waves', 'blocked_deletions', 'expected_line_reduction', 'recommended_next_batch'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    public function test_ready_summary_case_safe_candidate_goes(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id'            => 'delete:organ_a',
                    'action'                  => 'delete',
                    'area'                    => 'external_brain',
                    'risk_level'              => 'low',
                    'expected_line_reduction' => 120,
                    'behavior_lock_present'   => true,
                    'proof_gates_passed'      => true,
                    'hotspot_score'           => 0.9,
                ],
            ],
        ]);

        $this->assertSame('go', $r['readiness']);
        $this->assertSame(['delete:organ_a'], $r['safe_waves']);
        $this->assertSame([], $r['blocked_deletions']);
        $this->assertSame(120, $r['expected_line_reduction']);
        $this->assertSame(['delete:organ_a'], $r['recommended_next_batch']);
        $this->assertNotEmpty($r['top_hotspots']);
        $this->assertSame('delete:organ_a', $r['top_hotspots'][0]['candidate_id']);
    }

    public function test_hold_missing_lock_case_high_roi_still_held(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id'            => 'delete:big_organ',
                    'action'                  => 'delete',
                    'area'                    => 'external_brain',
                    'risk_level'              => 'low',
                    'expected_line_reduction' => 5000, // huge ROI on paper
                    'behavior_lock_present'   => false, // missing lock — must hold regardless
                    'proof_gates_passed'      => true,
                    'hotspot_score'           => 0.99,
                ],
            ],
        ]);

        $this->assertSame('hold', $r['readiness']);
        $this->assertSame([], $r['safe_waves']);
        $this->assertSame(0, $r['expected_line_reduction']);
        $this->assertSame([], $r['recommended_next_batch']);
        $this->assertCount(1, $r['blocked_deletions']);
        $this->assertSame('delete:big_organ', $r['blocked_deletions'][0]['candidate_id']);
        $this->assertStringContainsString('missing_behavior_lock', $r['blocked_deletions'][0]['reason']);
    }

    public function test_hold_missing_proof_gates_case(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id'            => 'merge:group_1',
                    'action'                  => 'merge',
                    'risk_level'              => 'low',
                    'expected_line_reduction' => 300,
                    'behavior_lock_present'   => true,
                    'proof_gates_passed'      => false,
                ],
            ],
        ]);

        $this->assertSame('hold', $r['readiness']);
        $this->assertStringContainsString('missing_proof_gate_evidence', $r['blocked_deletions'][0]['reason']);
    }

    public function test_keep_action_candidates_never_appear_in_safe_waves_or_blocked_deletions(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id' => 'keep:organ_x',
                    'action'       => 'keep',
                    'hotspot_score' => 0.5,
                ],
            ],
        ]);

        $this->assertSame([], $r['safe_waves']);
        $this->assertSame([], $r['blocked_deletions']);
        $this->assertNotEmpty($r['top_hotspots'], 'keep candidates are still surfaced as hotspots');
    }

    public function test_partial_go_when_mix_of_safe_and_blocked_candidates(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id'            => 'simplify:safe_one',
                    'action'                  => 'simplify',
                    'risk_level'              => 'low',
                    'expected_line_reduction' => 40,
                    'behavior_lock_present'   => true,
                    'proof_gates_passed'      => true,
                ],
                [
                    'candidate_id'            => 'delete:unsafe_one',
                    'action'                  => 'delete',
                    'risk_level'              => 'low',
                    'expected_line_reduction' => 999,
                    'behavior_lock_present'   => false,
                    'proof_gates_passed'      => false,
                ],
            ],
        ]);

        $this->assertSame('partial_go', $r['readiness']);
        $this->assertSame(['simplify:safe_one'], $r['safe_waves']);
        $this->assertSame(40, $r['expected_line_reduction']);
        $this->assertCount(1, $r['blocked_deletions']);
    }

    public function test_high_risk_mutating_candidate_is_held_even_with_lock_and_proof(): void
    {
        $r = $this->controlPlane()->evaluate([
            'candidates' => [
                [
                    'candidate_id'            => 'delete:risky',
                    'action'                  => 'delete',
                    'risk_level'              => 'high',
                    'expected_line_reduction' => 10,
                    'behavior_lock_present'   => true,
                    'proof_gates_passed'      => true,
                ],
            ],
        ]);

        $this->assertSame('hold', $r['readiness']);
        $this->assertStringContainsString('high_risk', $r['blocked_deletions'][0]['reason']);
    }

    public function test_empty_candidates_is_hold_with_empty_collections(): void
    {
        $r = $this->controlPlane()->evaluate([]);

        $this->assertSame('hold', $r['readiness']);
        $this->assertSame([], $r['safe_waves']);
        $this->assertSame([], $r['blocked_deletions']);
        $this->assertSame([], $r['top_hotspots']);
        $this->assertSame(0, $r['expected_line_reduction']);
        $this->assertSame([], $r['recommended_next_batch']);
    }

    public function test_schema_is_present(): void
    {
        $r = $this->controlPlane()->evaluate(['candidates' => []]);

        $this->assertSame(AtlasExternalBrainCompressionControlPlane::SCHEMA, $r['schema']);
    }
}
