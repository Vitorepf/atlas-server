<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDeletionRoiRanker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDeletionRoiRankerTest extends TestCase
{
    private function svc(): AtlasExternalBrainDeletionRoiRanker
    {
        return new AtlasExternalBrainDeletionRoiRanker;
    }

    private function safeCandidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id'          => 'organ-a',
            'lines_removed'         => 100,
            'capability_preserved'  => true,
            'replacement_owner'     => 'NewService',
            'test_coverage'         => true,
            'risk_level'            => 'low',
        ], $overrides);
    }

    // ── safe delete ranking ────────────────────────────────────────────────────

    public function test_high_line_removal_with_preserved_capability_outranks_lower_removal(): void
    {
        $result = $this->svc()->rank([
            $this->safeCandidate(['candidate_id' => 'small', 'lines_removed' => 20]),
            $this->safeCandidate(['candidate_id' => 'big', 'lines_removed' => 500]),
        ]);

        $this->assertSame('big', $result['ranked'][0]['candidate_id']);
        $this->assertGreaterThan($result['ranked'][1]['net_roi'], $result['ranked'][0]['net_roi']);
    }

    public function test_preserved_capability_outranks_lost_capability_at_equal_lines(): void
    {
        $result = $this->svc()->rank([
            $this->safeCandidate(['candidate_id' => 'loses_capability', 'lines_removed' => 100, 'capability_preserved' => false]),
            $this->safeCandidate(['candidate_id' => 'preserves_capability', 'lines_removed' => 100, 'capability_preserved' => true]),
        ]);

        $this->assertSame('preserves_capability', $result['ranked'][0]['candidate_id']);
        $this->assertGreaterThan($result['ranked'][1]['net_roi'], $result['ranked'][0]['net_roi']);
    }

    public function test_lower_risk_outranks_higher_risk_at_equal_lines_and_capability(): void
    {
        $result = $this->svc()->rank([
            $this->safeCandidate(['candidate_id' => 'risky', 'risk_level' => 'high']),
            $this->safeCandidate(['candidate_id' => 'safe', 'risk_level' => 'low']),
        ]);

        $this->assertSame('safe', $result['ranked'][0]['candidate_id']);
        $this->assertGreaterThan($result['ranked'][1]['net_roi'], $result['ranked'][0]['net_roi']);
    }

    public function test_safe_candidate_has_no_refusal_reasons_and_positive_roi(): void
    {
        $result = $this->svc()->rank([$this->safeCandidate()]);

        $entry = $result['ranked'][0];
        $this->assertTrue($entry['safe']);
        $this->assertSame([], $entry['refusal_reasons']);
        $this->assertSame([], $entry['required_prework']);
        $this->assertGreaterThan(0.0, $entry['net_roi']);
    }

    // ── unsafe delete rejection ────────────────────────────────────────────────

    public function test_missing_replacement_owner_is_refused_regardless_of_lines_removed(): void
    {
        $result = $this->svc()->rank([$this->safeCandidate([
            'lines_removed' => 5000,
            'replacement_owner' => '',
        ])]);

        $entry = $result['ranked'][0];
        $this->assertFalse($entry['safe']);
        $this->assertContains('missing_replacement_owner', $entry['refusal_reasons']);
        $this->assertSame(0.0, $entry['net_roi']);
        $this->assertNotEmpty($entry['required_prework']);
    }

    public function test_missing_test_coverage_is_refused(): void
    {
        $result = $this->svc()->rank([$this->safeCandidate(['test_coverage' => false])]);

        $entry = $result['ranked'][0];
        $this->assertFalse($entry['safe']);
        $this->assertContains('missing_test_coverage', $entry['refusal_reasons']);
        $this->assertSame(0.0, $entry['net_roi']);
    }

    public function test_both_gates_missing_yields_both_refusal_reasons_and_prework(): void
    {
        $result = $this->svc()->rank([$this->safeCandidate([
            'replacement_owner' => '',
            'test_coverage' => false,
        ])]);

        $entry = $result['ranked'][0];
        $this->assertContains('missing_replacement_owner', $entry['refusal_reasons']);
        $this->assertContains('missing_test_coverage', $entry['refusal_reasons']);
        $this->assertCount(2, $entry['required_prework']);
    }

    public function test_unsafe_candidate_ranks_below_any_safe_positive_roi_candidate(): void
    {
        $result = $this->svc()->rank([
            $this->safeCandidate(['candidate_id' => 'unsafe', 'lines_removed' => 10000, 'test_coverage' => false]),
            $this->safeCandidate(['candidate_id' => 'safe', 'lines_removed' => 1]),
        ]);

        $this->assertSame('safe', $result['ranked'][0]['candidate_id']);
        $this->assertSame('unsafe', $result['ranked'][1]['candidate_id']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.deletion_roi_ranker.v1', AtlasExternalBrainDeletionRoiRanker::SCHEMA);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $candidates = [$this->safeCandidate(), $this->safeCandidate(['candidate_id' => 'b', 'risk_level' => 'high'])];

        $this->assertSame(json_encode($svc->rank($candidates)), json_encode($svc->rank($candidates)));
    }
}
