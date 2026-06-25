<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeReplenisherIntegration;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeReplenisherIntegrationTest extends TestCase
{
    public function test_top_up_request_with_bounded_target_and_deterministic_hash(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-1'],
            'queue_health' => [
                'malformed_count' => 0,
                'claimable_depth' => 1,
                'servable_depth' => 1,
                'depth_floor' => 3,
                'target_depth' => 6,
            ],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(5, $verdict['request']['target_new_packet_count']);
        $this->assertSame(['claimable_depth_below_floor:1<3'], $verdict['request']['reasons']);
        $this->assertSame('39a4a0c9d7caf15b51b02cd0feb624b062bb3f1e1dcd11beb917819991ecc3b0', $verdict['payload_hash']);
    }

    public function test_wait_when_claimable_depth_at_or_above_floor(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-2'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 5, 'depth_floor' => 3],
        ]);

        $this->assertSame('wait', $verdict['request']['action']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertSame('bd6dbb1164a63b132cd3690993b4e8a06481cd96be00ad0ef28f221c847ced68', $verdict['payload_hash']);
    }

    public function test_repair_first_when_malformed_packets_present(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-3'],
            'queue_health' => ['malformed_count' => 2, 'claimable_depth' => 5],
        ]);

        $this->assertSame('repair_first', $verdict['request']['action']);
        $this->assertSame(2, $verdict['request']['malformed_count']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertSame('2ab1895fc324c3cd9a68222b0f778c7fb68963095cc85601fe93a96706864809', $verdict['payload_hash']);
    }

    public function test_target_new_packet_count_is_bounded_to_max(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-4'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 0, 'depth_floor' => 3, 'target_depth' => 999],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(8, $verdict['request']['target_new_packet_count']);
    }

    public function test_envelope_carries_schema_version(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([]);
        $this->assertSame('atlas.continuous_runtime.replenisher_integration.v1', $verdict['schema_version']);
    }
}
