<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\AccountStructurer;
use App\Services\Ai\MarketingDomain\Campaign\BroadMatchStrategist;
use App\Services\Ai\MarketingDomain\Campaign\RSAWriter;
use PHPUnit\Framework\TestCase;

class MarketingAccountStructureTest extends TestCase
{
    public function test_structurer_picks_architecture_by_volume(): void
    {
        $structurer = new AccountStructurer;

        $few = $structurer->structure([['name' => 't1', 'terms' => ['a', 'b', 'c']]]);
        $this->assertSame('hagakure_broad_smart', $few['architecture']);

        $many = $structurer->structure([['name' => 'big', 'terms' => range(1, 250)]]);
        $this->assertSame('alpha_beta', $many['architecture']);
    }

    public function test_broad_match_tightens_without_signal_or_broken_loop(): void
    {
        $strat = new BroadMatchStrategist;

        $cold = $strat->matchMix(0);
        $this->assertSame(10, $cold['match_mix_pct']['broad']);

        $scaled = $strat->matchMix(30);
        $this->assertSame(70, $scaled['match_mix_pct']['broad']);

        // broken conversion loop forces tight regardless of volume
        $broken = $strat->matchMix(50, false);
        $this->assertSame(0, $broken['match_mix_pct']['broad']);
    }

    public function test_rsa_writer_fills_toward_15_headlines(): void
    {
        $rsa = (new RSAWriter)->write(
            ['name' => 'ag1', 'terms' => ['pink gelatin', 'jello diet']],
            ['headlines' => ['Existing H1', 'Existing H2'], 'descriptions' => ['d1']],
        );

        $this->assertLessThanOrEqual(15, count($rsa['headlines']));
        $this->assertGreaterThan(2, count($rsa['headlines']));
        $this->assertTrue($rsa['needs_review']); // fewer than 15 real headlines
        $this->assertCount(4, $rsa['descriptions']);
    }
}
