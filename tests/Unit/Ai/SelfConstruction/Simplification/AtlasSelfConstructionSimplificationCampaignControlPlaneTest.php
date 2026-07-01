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
}
