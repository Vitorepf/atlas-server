<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeFeedbackLoop;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeFeedbackLoopTest extends TestCase
{
    private function svc(): AtlasExternalBrainOutcomeFeedbackLoop
    {
        return new AtlasExternalBrainOutcomeFeedbackLoop;
    }

    // ── success promotes ──────────────────────────────────────────────────────

    public function test_success_with_required_evidence_promotes_the_matching_family(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'brain-gap-closure',
                'outcome_type' => 'success',
                'required_evidence_present' => true,
                'commit_sha' => 'abc123',
            ],
        ]);

        $this->assertContains('brain-gap-closure', $result['promoted_families']);
        $entry = $result['ranking_adjustments'][0];
        $this->assertSame(AtlasExternalBrainOutcomeFeedbackLoop::ADJUSTMENT_PROMOTE, $entry['adjustment']);
    }

    public function test_commit_outcome_type_is_treated_like_success(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'brain-gap-closure',
                'outcome_type' => 'commit',
                'required_evidence_present' => true,
                'commit_sha' => 'def456',
            ],
        ]);

        $this->assertContains('brain-gap-closure', $result['promoted_families']);
    }

    public function test_success_without_required_evidence_does_not_promote(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'brain-gap-closure',
                'outcome_type' => 'success',
                'required_evidence_present' => false,
            ],
        ]);

        $this->assertNotContains('brain-gap-closure', $result['promoted_families']);
        $this->assertSame(AtlasExternalBrainOutcomeFeedbackLoop::ADJUSTMENT_HOLD, $result['ranking_adjustments'][0]['adjustment']);
    }

    public function test_success_without_commit_sha_does_not_promote(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'brain-gap-closure',
                'outcome_type' => 'success',
                'required_evidence_present' => true,
                'commit_sha' => '',
            ],
        ]);

        $this->assertNotContains('brain-gap-closure', $result['promoted_families']);
    }

    // ── give_back / quarantine / weak_green demote or block with explicit reasons ──

    public function test_give_back_demotes_the_matching_family_with_explicit_reason(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'poison-family',
                'outcome_type' => 'give_back',
                'reason' => 'duplicate_key_already_resolved',
            ],
        ]);

        $this->assertContains('poison-family', $result['demoted_families']);
        $reasons = $result['ranking_adjustments'][0]['reasons'];
        $this->assertContains('give_back:duplicate_key_already_resolved', $reasons);
    }

    public function test_quarantine_blocks_the_matching_family_with_explicit_reason(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'poison-family',
                'outcome_type' => 'quarantine',
                'reason' => 'template_farm_pattern',
            ],
        ]);

        $this->assertContains('poison-family', $result['blocked_families']);
        $reasons = $result['ranking_adjustments'][0]['reasons'];
        $this->assertContains('quarantine:template_farm_pattern', $reasons);
    }

    public function test_weak_green_demotes_the_matching_family_with_explicit_reason(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'flaky-family',
                'outcome_type' => 'weak_green',
                'reason' => 'proxy_assertion_detected',
            ],
        ]);

        $this->assertContains('flaky-family', $result['demoted_families']);
        $reasons = $result['ranking_adjustments'][0]['reasons'];
        $this->assertContains('weak_green:proxy_assertion_detected', $reasons);
    }

    public function test_missing_reason_still_demotes_with_unspecified_marker(): void
    {
        $result = $this->svc()->process([
            ['task_family' => 'poison-family', 'outcome_type' => 'give_back'],
        ]);

        $this->assertContains('poison-family', $result['demoted_families']);
        $this->assertContains('give_back:unspecified_reason', $result['ranking_adjustments'][0]['reasons']);
    }

    // ── priority: quarantine taints the family even after a prior success ────

    public function test_quarantine_overrides_a_prior_success_for_the_same_family(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'mixed-family',
                'outcome_type' => 'success',
                'required_evidence_present' => true,
                'commit_sha' => 'abc123',
            ],
            [
                'task_family' => 'mixed-family',
                'outcome_type' => 'quarantine',
                'reason' => 'regression_detected_later',
            ],
        ]);

        $this->assertContains('mixed-family', $result['blocked_families']);
        $this->assertNotContains('mixed-family', $result['promoted_families']);
    }

    public function test_give_back_overrides_a_prior_success_for_the_same_family(): void
    {
        $result = $this->svc()->process([
            [
                'task_family' => 'mixed-family',
                'outcome_type' => 'success',
                'required_evidence_present' => true,
                'commit_sha' => 'abc123',
            ],
            [
                'task_family' => 'mixed-family',
                'outcome_type' => 'give_back',
                'reason' => 'scope_conflict',
            ],
        ]);

        $this->assertContains('mixed-family', $result['demoted_families']);
        $this->assertNotContains('mixed-family', $result['promoted_families']);
    }

    // ── ranking_adjustments consumable output ─────────────────────────────────

    public function test_output_includes_ranking_adjustments_with_family_and_reasons(): void
    {
        $result = $this->svc()->process([
            ['task_family' => 'a', 'outcome_type' => 'success', 'required_evidence_present' => true, 'commit_sha' => 'x'],
            ['task_family' => 'b', 'outcome_type' => 'give_back', 'reason' => 'r'],
        ]);

        $this->assertArrayHasKey('ranking_adjustments', $result);
        foreach ($result['ranking_adjustments'] as $entry) {
            $this->assertArrayHasKey('task_family', $entry);
            $this->assertArrayHasKey('adjustment', $entry);
            $this->assertArrayHasKey('reasons', $entry);
            $this->assertNotEmpty($entry['reasons']);
        }
    }

    public function test_unrelated_families_are_independent(): void
    {
        $result = $this->svc()->process([
            ['task_family' => 'good', 'outcome_type' => 'success', 'required_evidence_present' => true, 'commit_sha' => 'x'],
            ['task_family' => 'bad', 'outcome_type' => 'quarantine', 'reason' => 'poison'],
        ]);

        $this->assertContains('good', $result['promoted_families']);
        $this->assertContains('bad', $result['blocked_families']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.outcome_feedback_loop.v1', AtlasExternalBrainOutcomeFeedbackLoop::SCHEMA);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $outcomes = [
            ['task_family' => 'a', 'outcome_type' => 'success', 'required_evidence_present' => true, 'commit_sha' => 'x'],
            ['task_family' => 'b', 'outcome_type' => 'give_back', 'reason' => 'r'],
        ];

        $this->assertSame(json_encode($svc->process($outcomes)), json_encode($svc->process($outcomes)));
    }
}
