<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Training;

use App\Services\Ai\AutonomousEvolution\SelfModel\Training\AtlasLoopCrossDomainTrainingSampler;
use PHPUnit\Framework\TestCase;

/**
 * Proves the cross-domain training sampler: alphabetical domain order, per-domain cap, all-when-fewer, and
 * determinism.
 */
final class AtlasLoopCrossDomainTrainingSamplerTest extends TestCase
{
    private function sampler(): AtlasLoopCrossDomainTrainingSampler
    {
        return new AtlasLoopCrossDomainTrainingSampler;
    }

    public function test_alphabetical_order_and_cap(): void
    {
        $out = $this->sampler()->sample(['b' => [1, 2, 3], 'a' => [9]], 2);

        $this->assertSame([9, 1, 2], $out['sampled'], 'a first (1 example), then b (2 of 3)');
        $this->assertSame(['a' => 1, 'b' => 2], $out['per_domain_counts']);
        $this->assertSame(3, $out['total']);
        $this->assertSame('atlas.loop.cross_domain_sample.v1', $out['schema']);
    }

    public function test_domain_with_fewer_than_cap_contributes_all(): void
    {
        $out = $this->sampler()->sample(['x' => ['only']], 5);

        $this->assertSame(['only'], $out['sampled']);
        $this->assertSame(['x' => 1], $out['per_domain_counts']);
    }

    public function test_each_domain_contributes_at_most_the_cap(): void
    {
        $out = $this->sampler()->sample(['code' => [1, 2, 3, 4, 5, 6]], 3);

        $this->assertSame([1, 2, 3], $out['sampled']);
        $this->assertSame(3, $out['per_domain_counts']['code']);
        $this->assertSame(3, $out['total']);
    }

    public function test_is_deterministic(): void
    {
        $input = ['m' => [1, 2], 'c' => [3, 4], 't' => [5, 6]];

        $a = $this->sampler()->sample($input, 1);
        $b = $this->sampler()->sample($input, 1);

        $this->assertSame($a, $b);
        $this->assertSame([3, 1, 5], $a['sampled'], 'c, m, t order ⇒ first of each');
    }
}
