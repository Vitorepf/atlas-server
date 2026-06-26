<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeDryProbe;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * Proves the dry probe only returns 'dry' after m consecutive refusals AND zero
 * grounded gaps. Never fakes 'dry'.
 */
final class AtlasBrainScopeDryProbeTest extends TestCase
{
    public function test_returns_dry_when_m_consecutive_refusals_and_zero_gaps(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        $result = $probe->probe([
            ['produced' => false, 'action' => 'abstain', 'reason' => 'not_originated'],
            ['produced' => false, 'action' => 'abstain', 'reason' => 'no_resolvable_target'],
            ['produced' => false, 'action' => 'abstain', 'reason' => 'design_not_converged'],
        ], $model, 3);

        self::assertSame('dry', $result['state']);
        self::assertSame(3, $result['consecutive_refusals']);
        self::assertSame([], $result['new_grounded_gaps']);
    }

    public function test_returns_unknown_blocked_when_refusals_less_than_m(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        // Only 2 refusals, m=3.
        $result = $probe->probe([
            ['produced' => false, 'reason' => 'not_originated'],
            ['produced' => false, 'reason' => 'no_resolvable_target'],
        ], $model, 3);

        self::assertSame('unknown_blocked', $result['state']);
        self::assertSame(2, $result['consecutive_refusals']);
    }

    public function test_returns_unknown_blocked_when_grounded_gaps_remain(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        // Model has orphans — grounded gaps remain.
        $model = $this->modelWith(orphans: ['App\\Services\\OrphanClass'], docGaps: []);

        $result = $probe->probe([
            ['produced' => false],
            ['produced' => false],
            ['produced' => false],
        ], $model, 3);

        self::assertSame('unknown_blocked', $result['state']);
        self::assertSame(3, $result['consecutive_refusals']);
        self::assertNotEmpty($result['new_grounded_gaps']);
    }

    public function test_returns_unknown_blocked_when_doc_stated_gaps_remain(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: ['AtlasMissingCapability']);

        $result = $probe->probe([
            ['produced' => false],
            ['produced' => false],
            ['produced' => false],
        ], $model, 3);

        self::assertSame('unknown_blocked', $result['state']);
        self::assertContains('AtlasMissingCapability', $result['new_grounded_gaps']);
    }

    public function test_consecutive_refusals_broken_by_a_successful_cycle(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        // 3 refusals, but the LAST cycle succeeded — consecutive count = 0.
        $result = $probe->probe([
            ['produced' => false],
            ['produced' => false],
            ['produced' => false],
            ['produced' => true, 'action' => 'proceed'],
        ], $model, 3);

        self::assertSame('unknown_blocked', $result['state']);
        self::assertSame(0, $result['consecutive_refusals']);
    }

    public function test_m_default_is_3(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        // 3 refusals with default m.
        $result = $probe->probe([
            ['produced' => false],
            ['produced' => false],
            ['produced' => false],
        ], $model);

        self::assertSame('dry', $result['state']);
    }

    public function test_custom_m_respected(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        // m=5, only 3 refusals → unknown_blocked.
        $result = $probe->probe([
            ['produced' => false],
            ['produced' => false],
            ['produced' => false],
        ], $model, 5);

        self::assertSame('unknown_blocked', $result['state']);
    }

    public function test_empty_cycles_with_empty_model_returns_unknown_blocked(): void
    {
        $probe = new AtlasBrainScopeDryProbe;
        $model = $this->modelWith(orphans: [], docGaps: []);

        $result = $probe->probe([], $model);

        self::assertSame('unknown_blocked', $result['state']);
        self::assertSame(0, $result['consecutive_refusals']);
    }

    private function modelWith(array $orphans = [], array $docGaps = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: $orphans,
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: $docGaps,
            snapshotId: 'snap-test',
        );
    }
}
