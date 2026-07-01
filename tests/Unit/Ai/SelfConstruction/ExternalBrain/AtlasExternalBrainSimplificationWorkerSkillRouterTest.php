<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationWorkerSkillRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationWorkerSkillRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainSimplificationWorkerSkillRouter
    {
        return new AtlasExternalBrainSimplificationWorkerSkillRouter;
    }

    public function test_senior_route_case_high_risk(): void
    {
        $r = $this->router()->route(['risk_level' => 'high']);

        $this->assertSame('senior_worker', $r['assigned_profile']);
        $this->assertSame('senior_worker', $r['base_profile']);
        $this->assertSame([], $r['avoided_profiles']);
    }

    public function test_deep_proof_routes_to_proof_worker(): void
    {
        $r = $this->router()->route(['proof_depth' => 'deep']);

        $this->assertSame('proof_worker', $r['assigned_profile']);
    }

    public function test_boundary_file_routes_to_senior_worker(): void
    {
        $r = $this->router()->route(['file_kind' => 'boundary']);

        $this->assertSame('senior_worker', $r['assigned_profile']);
    }

    public function test_default_routes_to_safe_worker(): void
    {
        $r = $this->router()->route([]);

        $this->assertSame('safe_worker', $r['assigned_profile']);
    }

    public function test_give_back_avoidance_case_routes_away_from_failing_profile(): void
    {
        $r = $this->router()->route([
            'risk_level' => 'high',
            'give_back_history' => ['senior_worker' => 3],
        ]);

        $this->assertNotSame('senior_worker', $r['assigned_profile']);
        $this->assertSame('proof_worker', $r['assigned_profile']);
        $this->assertContains('senior_worker', $r['avoided_profiles']);
    }

    public function test_all_profiles_failing_holds_for_respec(): void
    {
        $r = $this->router()->route([
            'risk_level' => 'high',
            'give_back_history' => [
                'senior_worker' => 5,
                'proof_worker' => 5,
                'safe_worker' => 5,
            ],
        ]);

        $this->assertSame('hold', $r['assigned_profile']);
    }

    public function test_give_back_below_threshold_does_not_trigger_avoidance(): void
    {
        $r = $this->router()->route([
            'risk_level' => 'high',
            'give_back_history' => ['senior_worker' => 1],
        ]);

        $this->assertSame('senior_worker', $r['assigned_profile']);
    }

    public function test_schema_present(): void
    {
        $r = $this->router()->route([]);

        $this->assertSame(AtlasExternalBrainSimplificationWorkerSkillRouter::SCHEMA, $r['schema']);
    }
}
