<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationCampaignControlPlane;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationCampaignControlPlaneTest extends TestCase
{
    private function controlPlane(): AtlasSelfConstructionSimplificationCampaignControlPlane
    {
        return new AtlasSelfConstructionSimplificationCampaignControlPlane;
    }

    private function safeInput(): array
    {
        return [
            'redundancy_map' => ['clusters' => [['cluster_id' => 'c1', 'members' => ['A', 'B']]]],
            'equivalence_dossier' => ['behavior_equivalence_proven' => true],
            'deletion_plan' => ['safe' => true, 'targets' => ['organ-b']],
            'consumer_impact' => ['unsafe_consumers' => []],
            'parity_matrix' => ['parity_verified' => true],
            'rollback_receipts' => ['present' => true],
            'replay_plan' => ['ready' => true],
            'docs_sync' => ['required' => false],
        ];
    }

    public function test_go_decision_when_everything_safe(): void
    {
        $result = $this->controlPlane()->decide($this->safeInput());

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_GO, $result['decision']);
        $this->assertSame(['organ-b'], $result['safe_waves']);
        $this->assertSame([], $result['blocked_waves']);
        $this->assertTrue($result['proof_readiness']);
        $this->assertTrue($result['rollback_readiness']);
        $this->assertFalse($result['docs_sync_required']);
    }

    public function test_hold_decision_when_equivalence_not_proven(): void
    {
        $input = $this->safeInput();
        $input['equivalence_dossier']['behavior_equivalence_proven'] = false;

        $result = $this->controlPlane()->decide($input);

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_HOLD, $result['decision']);
        $this->assertContains('behavior_equivalence_not_proven', $result['reasons']);
    }

    public function test_hold_decision_when_docs_sync_required(): void
    {
        $input = $this->safeInput();
        $input['docs_sync']['required'] = true;

        $result = $this->controlPlane()->decide($input);

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_HOLD, $result['decision']);
        $this->assertTrue($result['docs_sync_required']);
    }

    public function test_fail_closed_when_a_required_section_is_missing(): void
    {
        $input = $this->safeInput();
        unset($input['rollback_receipts']);

        $result = $this->controlPlane()->decide($input);

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_FAIL_CLOSED, $result['decision']);
        $this->assertContains('missing_section:rollback_receipts', $result['reasons']);
    }

    public function test_fail_closed_when_deletion_plan_unsafe(): void
    {
        $input = $this->safeInput();
        $input['deletion_plan']['safe'] = false;

        $result = $this->controlPlane()->decide($input);

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_FAIL_CLOSED, $result['decision']);
        $this->assertContains('unsafe_deletion_plan', $result['reasons']);
    }

    public function test_fail_closed_when_unsafe_consumers_present(): void
    {
        $input = $this->safeInput();
        $input['consumer_impact']['unsafe_consumers'] = ['app/Legacy/Consumer.php'];

        $result = $this->controlPlane()->decide($input);

        $this->assertSame(AtlasSelfConstructionSimplificationCampaignControlPlane::DECISION_FAIL_CLOSED, $result['decision']);
        $this->assertContains('unsafe_consumer_impact', $result['reasons']);
    }

    public function test_output_exposes_all_required_keys(): void
    {
        $result = $this->controlPlane()->decide($this->safeInput());

        foreach (['decision', 'reasons', 'safe_waves', 'blocked_waves', 'next_action', 'proof_readiness', 'rollback_readiness', 'docs_sync_required'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    // ── planCampaign: batch candidate-set decisions ───────────────────────────

    private function candidate(string $id, string $actionType, string $risk, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'action_type' => $actionType,
            'risk' => $risk,
        ], $this->safeInput(), $overrides);
    }

    public function test_deletion_first_candidates_lead_additive_in_next_wave(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('cleanup-a', 'additive_cleanup', 'low'),
                $this->candidate('organ-b', 'delete', 'low'),
                $this->candidate('merge-c', 'merge', 'low'),
            ],
        ]);

        $this->assertSame(['organ-b', 'merge-c'], $result['deletion_first_candidates']);
        $this->assertSame(['cleanup-a'], $result['additive_candidates']);
        $this->assertSame(['organ-b', 'merge-c', 'cleanup-a'], $result['next_wave']);
    }

    public function test_high_risk_candidate_missing_replay_proof_is_blocked(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('risky-delete', 'delete', 'high', ['replay_plan' => ['ready' => false]]),
            ],
        ]);

        $this->assertSame(['risky-delete'], $result['blocked_high_risk_candidates']);
        $this->assertSame([], $result['deletion_first_candidates']);
        $this->assertSame([], $result['next_wave']);
    }

    public function test_high_risk_candidate_missing_parity_is_blocked(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('risky-merge', 'merge', 'high', ['parity_matrix' => ['parity_verified' => false]]),
            ],
        ]);

        $this->assertSame(['risky-merge'], $result['blocked_high_risk_candidates']);
    }

    public function test_high_risk_candidate_with_full_proof_is_not_blocked(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('proven-delete', 'delete', 'high'),
            ],
        ]);

        $this->assertSame([], $result['blocked_high_risk_candidates']);
        $this->assertSame(['proven-delete'], $result['deletion_first_candidates']);
    }

    public function test_knowledge_sync_required_after_merge_delete_or_import_rewrite(): void
    {
        $deleteResult = $this->controlPlane()->planCampaign([
            'candidates' => [$this->candidate('organ-b', 'delete', 'low')],
        ]);
        $this->assertTrue($deleteResult['knowledge_sync_required']);

        $importResult = $this->controlPlane()->planCampaign([
            'candidates' => [$this->candidate('module-x', 'import_rewrite', 'low')],
        ]);
        $this->assertTrue($importResult['knowledge_sync_required']);

        $additiveResult = $this->controlPlane()->planCampaign([
            'candidates' => [$this->candidate('cleanup-a', 'additive_cleanup', 'low')],
        ]);
        $this->assertFalse($additiveResult['knowledge_sync_required']);
    }

    public function test_unproven_low_risk_candidate_is_held_not_placed_in_next_wave(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('unproven', 'delete', 'low', ['equivalence_dossier' => ['behavior_equivalence_proven' => false]]),
            ],
        ]);

        $this->assertSame(['unproven'], $result['held_candidates']);
        $this->assertSame([], $result['next_wave']);
        $this->assertSame([], $result['blocked_high_risk_candidates']);
    }

    // ── AC3: campaign output includes proof_readiness/rollback_readiness/blocked_waves ──

    public function test_plan_campaign_output_includes_required_keys(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [$this->candidate('organ-b', 'delete', 'low')],
        ]);

        foreach (['proof_readiness', 'rollback_readiness', 'knowledge_sync_required', 'deletion_first_candidates', 'blocked_waves'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing key: {$k}");
        }
        $this->assertTrue($result['proof_readiness']['organ-b']);
        $this->assertTrue($result['rollback_readiness']['organ-b']);
        $this->assertSame([], $result['blocked_waves']);
    }

    public function test_blocked_waves_includes_held_and_blocked_high_risk_candidates(): void
    {
        $result = $this->controlPlane()->planCampaign([
            'candidates' => [
                $this->candidate('risky-delete', 'delete', 'high', ['replay_plan' => ['ready' => false]]),
                $this->candidate('unproven', 'delete', 'low', ['equivalence_dossier' => ['behavior_equivalence_proven' => false]]),
            ],
        ]);

        $this->assertContains('risky-delete', $result['blocked_waves']);
        $this->assertContains('unproven', $result['blocked_waves']);
        $this->assertFalse($result['proof_readiness']['unproven']);
    }
}
