<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasIntelligenceRolloutMode;
use Tests\TestCase;

final class AtlasIntelligenceRolloutModeTest extends TestCase
{
    public function test_kill_switch_forces_offline(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => false,
            'mode' => AtlasIntelligenceRolloutMode::DEFAULT,
        ]);

        $this->assertSame(AtlasIntelligenceRolloutMode::OFFLINE, $mode);
        $this->assertFalse(AtlasIntelligenceRolloutMode::shouldExecuteLive($mode));
        $this->assertFalse(AtlasIntelligenceRolloutMode::shouldRecordShadow($mode));
    }

    public function test_legacy_enabled_true_with_offline_mode_promotes_to_default(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => true,
            'mode' => AtlasIntelligenceRolloutMode::OFFLINE,
        ]);

        $this->assertSame(AtlasIntelligenceRolloutMode::DEFAULT, $mode);
        $this->assertTrue(AtlasIntelligenceRolloutMode::shouldExecuteLive($mode));
    }

    public function test_shadow_records_but_does_not_go_live(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => true,
            'mode' => AtlasIntelligenceRolloutMode::SHADOW,
        ]);

        $this->assertSame(AtlasIntelligenceRolloutMode::SHADOW, $mode);
        $this->assertTrue(AtlasIntelligenceRolloutMode::shouldRecordShadow($mode));
        $this->assertFalse(AtlasIntelligenceRolloutMode::shouldExecuteLive($mode));
    }

    public function test_canary_falls_back_to_shadow_when_bucket_misses(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => true,
            'mode' => AtlasIntelligenceRolloutMode::CANARY,
            'canary_percent' => 0,
        ], ['workspace' => 'ws-a', 'flow_id' => 'f1']);

        $this->assertSame(AtlasIntelligenceRolloutMode::SHADOW, $mode);
    }

    public function test_canary_allows_when_percent_is_100(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => true,
            'mode' => AtlasIntelligenceRolloutMode::CANARY,
            'canary_percent' => 100,
        ], ['workspace' => 'ws-a', 'flow_id' => 'f1']);

        $this->assertSame(AtlasIntelligenceRolloutMode::CANARY, $mode);
        $this->assertTrue(AtlasIntelligenceRolloutMode::shouldExecuteLive($mode));
    }

    public function test_invalid_mode_falls_back_to_offline_then_legacy_default_when_enabled(): void
    {
        $mode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => true,
            'mode' => 'not-a-mode',
        ]);

        $this->assertSame(AtlasIntelligenceRolloutMode::DEFAULT, $mode);
    }

    public function test_receipt_schema(): void
    {
        $receipt = AtlasIntelligenceRolloutMode::receipt(AtlasIntelligenceRolloutMode::SHADOW, true);

        $this->assertSame('atlas.intelligence.rollout.v1', $receipt['schema_version']);
        $this->assertSame(AtlasIntelligenceRolloutMode::SHADOW, $receipt['mode']);
        $this->assertFalse($receipt['live']);
        $this->assertTrue($receipt['shadow']);
        $this->assertFalse($receipt['kill_switch_off']);
    }
}
