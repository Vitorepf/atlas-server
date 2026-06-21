<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Characterizes the current sibling-test gate used when reopening policy-blocked
 * refactor targets for structured supply.
 */
final class AtlasLoopTargetRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    public function test_policy_blocked_refactor_target_without_sibling_test_stays_quarantined(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => true,
        ]);

        $campaign = $this->campaign();
        $target = $this->quarantinedRefactorTarget($campaign, [
            'has_sibling_test' => false,
            'sibling_test_path' => '',
            'cyclomatic' => 12,
        ]);

        $reopened = app(AtlasLoopTargetRepository::class)
            ->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4);

        $this->assertSame(0, $reopened);
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->fresh()->status);
        $this->assertSame('generic_provider_fallback_disabled', $target->fresh()->reason);
    }

    public function test_policy_blocked_refactor_target_with_sibling_but_low_cyclomatic_stays_quarantined(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 10,
        ]);

        $campaign = $this->campaign();
        $target = $this->quarantinedRefactorTarget($campaign, [
            'has_sibling_test' => true,
            'sibling_test_path' => '',
            'cyclomatic' => 9,
        ]);

        $reopened = app(AtlasLoopTargetRepository::class)
            ->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4);

        $this->assertSame(0, $reopened);
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->fresh()->status);
        $this->assertSame('generic_provider_fallback_disabled', $target->fresh()->reason);
    }

    public function test_policy_blocked_refactor_target_with_sibling_flag_reopens(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => true,
        ]);

        $campaign = $this->campaign();
        $target = $this->quarantinedRefactorTarget($campaign, [
            'has_sibling_test' => true,
            'sibling_test_path' => '',
            'cyclomatic' => 12,
        ]);

        $reopened = app(AtlasLoopTargetRepository::class)
            ->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4);

        $this->assertSame(1, $reopened);
        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('policy_unblocked_reopened', $target->reason);
        $this->assertNull($target->claimed_by);
        $this->assertNull($target->claimed_at);
        $this->assertNull($target->lease_expires_at);
    }

    public function test_policy_blocked_refactor_target_with_sibling_test_path_reopens(): void
    {
        config([
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.proxy_refactor_supply_enabled' => true,
        ]);

        $campaign = $this->campaign();
        $target = $this->quarantinedRefactorTarget($campaign, [
            'has_sibling_test' => false,
            'sibling_test_path' => 'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopTargetRepositoryTest.php',
            'cyclomatic' => 12,
        ]);

        $reopened = app(AtlasLoopTargetRepository::class)
            ->reopenPolicyBlockedForStructuredSupply((string) $campaign->id, 4);

        $this->assertSame(1, $reopened);
        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('policy_unblocked_reopened', $target->reason);
        $this->assertNull($target->claimed_by);
        $this->assertNull($target->claimed_at);
        $this->assertNull($target->lease_expires_at);
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'atlas loop target repository characterization',
            'base_workspace' => base_path(),
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function quarantinedRefactorTarget(AtlasLoopCampaign $campaign, array $signals): AtlasLoopTarget
    {
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTargetRepository.php',
            hash('sha256', 'atlas-loop-target-repository'),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => $signals + [
                    'framework_reach' => 1,
                    'heavy_refactor_candidate' => true,
                ],
            ],
            ['origin' => 'discovery'],
        );

        app(AtlasLoopTargetRepository::class)->quarantine($target->id, 'generic_provider_fallback_disabled');

        return $target->fresh();
    }
}
