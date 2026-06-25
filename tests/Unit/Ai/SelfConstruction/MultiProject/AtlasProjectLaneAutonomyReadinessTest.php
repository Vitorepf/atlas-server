<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneAutonomyReadiness;
use Tests\TestCase;

final class AtlasProjectLaneAutonomyReadinessTest extends TestCase
{
    private function happyOrgans(): array
    {
        return [
            'admission' => ['admitted' => true],
            'freshness' => ['conformant' => true],
            'isolation' => ['passed' => true],
            'verification_court' => ['passed' => true],
            'release_governor' => ['passed' => true],
            'receipt_policy' => ['passed' => true],
            'knowledge_sync' => ['ready' => true],
        ];
    }

    public function test_all_organs_pass_yields_ready(): void
    {
        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('atlas-server', $this->happyOrgans());

        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_READY, $verdict['state']);
        $this->assertTrue($verdict['ready']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['holds']);
        $this->assertSame('atlas-server', $verdict['project_id']);
    }

    public function test_stale_context_freshness_is_hold_not_blocked(): void
    {
        $organs = $this->happyOrgans();
        $organs['freshness'] = ['conformant' => false, 'blockers' => ['docs_sync_stale']];

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_HOLD, $verdict['state']);
        $this->assertFalse($verdict['ready']);
        $this->assertContains('context_freshness_stale', $verdict['holds']);
        $this->assertContains('refresh_context_pack', $verdict['next_atlas_actions']);
    }

    public function test_admission_failure_blocks(): void
    {
        $organs = $this->happyOrgans();
        $organs['admission'] = ['admitted' => false, 'blocking_reasons' => ['unsafe_repo_root']];

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_BLOCKED, $verdict['state']);
        $this->assertContains('admission_failed', $verdict['blockers']);
        $this->assertContains('admission:unsafe_repo_root', $verdict['blockers']);
    }

    public function test_isolation_leak_blocks(): void
    {
        $organs = $this->happyOrgans();
        $organs['isolation'] = ['passed' => false, 'leaked' => ['/etc/passwd']];

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_BLOCKED, $verdict['state']);
        $this->assertContains('isolation_leak', $verdict['blockers']);
        $this->assertContains('isolation:/etc/passwd', $verdict['blockers']);
    }

    public function test_verification_failure_blocks(): void
    {
        $organs = $this->happyOrgans();
        $organs['verification_court'] = ['passed' => false, 'failures' => ['phpunit:exit_1']];

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_BLOCKED, $verdict['state']);
        $this->assertContains('verification_failed', $verdict['blockers']);
        $this->assertContains('verification:phpunit:exit_1', $verdict['blockers']);
    }

    public function test_release_governor_failure_blocks(): void
    {
        $organs = $this->happyOrgans();
        $organs['release_governor'] = ['passed' => false, 'failures' => ['anti_farm_floor_red']];

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_BLOCKED, $verdict['state']);
        $this->assertContains('release_failed', $verdict['blockers']);
        $this->assertContains('release:anti_farm_floor_red', $verdict['blockers']);
    }

    public function test_block_dominates_hold_when_both_present(): void
    {
        $organs = $this->happyOrgans();
        $organs['admission'] = ['admitted' => false];     // BLOCK
        $organs['freshness'] = ['conformant' => false];   // HOLD

        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $organs);
        $this->assertSame(AtlasProjectLaneAutonomyReadiness::STATE_BLOCKED, $verdict['state']);
        $this->assertContains('admission_failed', $verdict['blockers']);
        $this->assertContains('context_freshness_stale', $verdict['holds']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasProjectLaneAutonomyReadiness)->compose('p', $this->happyOrgans());
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
        }
    }

    public function test_compose_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasProjectLaneAutonomyReadiness;
        $organs = $this->happyOrgans();
        $this->assertSame(json_encode($svc->compose('p', $organs)), json_encode($svc->compose('p', $organs)));
    }
}
