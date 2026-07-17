<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;
use App\Services\Ai\SelfConstruction\Simplification\AtlasAaeosAcosSimplificationLane;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationOutcomeCreditGate;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosAcosSimplificationLaneTest extends TestCase
{
    public function test_elite_credit_rejects_loc_only(): void
    {
        $result = (new AtlasSelfConstructionSimplificationOutcomeCreditGate)->evaluateElite([
            'lines_removed' => 400,
            'files_deleted' => 0,
            'cyclomatic_reduction' => 0,
            'capability_preserved' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationOutcomeCreditGate::VERDICT_ZERO_CREDIT, $result['verdict']);
        $this->assertContains('loc_only_credit_forbidden', $result['reasons']);
        $this->assertTrue($result['elite']);
    }

    public function test_elite_credit_rejects_proxy_faxina(): void
    {
        $result = (new AtlasSelfConstructionSimplificationOutcomeCreditGate)->evaluateElite([
            'files_deleted' => 2,
            'capability_preserved' => true,
            'proxy_faxina' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationOutcomeCreditGate::VERDICT_ZERO_CREDIT, $result['verdict']);
        $this->assertContains('proxy_faxina_forbidden_on_elite_lane', $result['reasons']);
    }

    public function test_elite_credit_accepts_deletion_with_capability(): void
    {
        $result = (new AtlasAaeosAcosSimplificationLane)->creditOutcome([
            'files_deleted' => 2,
            'lines_removed' => 10,
            'capability_preserved' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationOutcomeCreditGate::VERDICT_CREDITED, $result['verdict']);
        $this->assertGreaterThan(0, $result['credit']);
    }

    public function test_deletion_blocked_when_unsafe_consumers(): void
    {
        $lane = new AtlasAaeosAcosSimplificationLane;
        $result = $lane->evaluateDeletion(
            [
                'behavior_equivalence_proven' => true,
                'consumers' => [['covered' => true]],
                'replacement_owner' => 'App\\Services\\Ai\\Aaeos\\Survivor',
                'rollback_notes' => 'git revert',
                'allowed_files' => ['app/Services/Ai/Aaeos/Dead.php'],
            ],
            [
                'consumers' => [
                    ['name' => 'LiveCaller', 'category' => 'runtime', 'proof_refs' => []],
                ],
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertContains('unsafe_consumers_present', $result['reasons']);
    }

    public function test_campaign_tags_lane_scope(): void
    {
        $lane = new AtlasAaeosAcosSimplificationLane;
        $result = $lane->planCampaign([
            'redundancy_map' => ['clusters' => []],
            'equivalence_dossier' => ['behavior_equivalence_proven' => false],
            'deletion_plan' => ['safe' => false, 'targets' => []],
            'consumer_impact' => ['unsafe_consumers' => []],
            'parity_matrix' => ['parity_verified' => false],
            'rollback_receipts' => ['present' => false],
            'replay_plan' => ['ready' => false],
            'docs_sync' => ['required' => false],
            'candidates' => [],
        ]);

        $this->assertSame(AtlasAaeosAcosLaneScope::SLUG, $result['lane_scope']);
        $this->assertSame(AtlasAaeosAcosSimplificationLane::CAMPAIGN_ID, $result['campaign_id']);
        $this->assertTrue($result['elite_rules']['loc_only_credit_forbidden']);
    }
}
