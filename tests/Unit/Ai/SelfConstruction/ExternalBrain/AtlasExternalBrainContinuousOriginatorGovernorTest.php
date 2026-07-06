<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContinuousOriginatorGovernor;
use Tests\TestCase;

final class AtlasExternalBrainContinuousOriginatorGovernorTest extends TestCase
{
    private function governor(): AtlasExternalBrainContinuousOriginatorGovernor
    {
        return new AtlasExternalBrainContinuousOriginatorGovernor;
    }

    // ── AC: sufficient_depth returns continue_with_smaller_batch ──

    public function test_sufficient_depth_returns_continue_with_smaller_batch(): void
    {
        $result = $this->governor()->govern([
            'claimable_depth' => 50,
            'active_workers' => 3,
        ]);

        $this->assertSame('continue_with_smaller_batch', $result['decision']);
        $this->assertFalse($result['is_completion']);
    }

    // ── AC: repeated vein failure returns pivot ──

    public function test_repeated_vein_failure_returns_pivot(): void
    {
        $result = $this->governor()->govern([
            'claimable_depth' => 0,
            'active_workers' => 0,
            'repeated_vein_failures' => 4,
        ]);

        $this->assertSame('pivot', $result['decision']);
    }

    // ── AC: explicit quota reached returns stop ──

    public function test_quota_reached_returns_stop(): void
    {
        $result = $this->governor()->govern([
            'claimable_depth' => 0,
            'active_workers' => 0,
            'quota_reached' => true,
        ]);

        $this->assertSame('stop', $result['decision']);
    }

    // ── default continue ──

    public function test_no_blocking_condition_returns_continue(): void
    {
        $result = $this->governor()->govern([
            'claimable_depth' => 0,
            'active_workers' => 0,
        ]);

        $this->assertSame('continue', $result['decision']);
    }

    // ── quota_reached takes priority over everything ──

    public function test_quota_reached_takes_priority_over_vein_failure(): void
    {
        $result = $this->governor()->govern([
            'repeated_vein_failures' => 10,
            'quota_reached' => true,
        ]);

        $this->assertSame('stop', $result['decision']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->governor()->govern([]);

        $this->assertSame(AtlasExternalBrainContinuousOriginatorGovernor::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('is_completion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = ['claimable_depth' => 10, 'active_workers' => 2];

        $a = $this->governor()->govern($input);
        $b = $this->governor()->govern($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
