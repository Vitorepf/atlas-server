<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3MetaStrategyDistiller;
use PHPUnit\Framework\TestCase;

/**
 * Proves the meta-strategy distiller over an in-memory fake ledger: cross-campaign signals are promoted, while
 * single-campaign or sub-threshold signals fall to single_campaign_noise; deterministic; reads only.
 */
final class AtlasLoopV3MetaStrategyDistillerTest extends TestCase
{
    /** @param array<string, list<array<string,mixed>>> $byCampaign */
    private function ledger(array $byCampaign): object
    {
        return new class($byCampaign)
        {
            /** @param array<string, list<array<string,mixed>>> $byCampaign */
            public function __construct(private array $byCampaign) {}

            /** @return list<array<string,mixed>> */
            public function read(string $campaignId): array
            {
                return $this->byCampaign[$campaignId] ?? [];
            }
        };
    }

    private function parked(): array
    {
        return ['status' => 'parked', 'reason' => 'forbidden_target_petreo'];
    }

    private function converged(): array
    {
        return ['status' => 'converged', 'reason' => 'goal_met'];
    }

    public function test_three_occurrences_two_campaigns_is_promoted(): void
    {
        $distiller = new AtlasLoopV3MetaStrategyDistiller($this->ledger([
            'campA' => [$this->parked(), $this->parked()],
            'campB' => [$this->parked()],
        ]));

        $out = $distiller->distill(['campA', 'campB']);

        $this->assertCount(1, $out['strategies']);
        $this->assertSame('avoid', $out['strategies'][0]['kind']);
        $this->assertSame('forbidden_target_petreo', $out['strategies'][0]['reason']);
        $this->assertSame(3, $out['strategies'][0]['count']);
        $this->assertSame(2, $out['strategies'][0]['campaigns']);
        $this->assertSame([], $out['single_campaign_noise']);
        $this->assertSame('atlas.loop.v3.meta_strategy_distiller.v1', $out['schema']);
    }

    public function test_three_occurrences_one_campaign_is_noise(): void
    {
        $distiller = new AtlasLoopV3MetaStrategyDistiller($this->ledger([
            'campA' => [$this->parked(), $this->parked(), $this->parked()],
        ]));

        $out = $distiller->distill(['campA']);

        $this->assertSame([], $out['strategies']);
        $this->assertCount(1, $out['single_campaign_noise']);
        $this->assertSame('insufficient_cross_campaign_evidence', $out['single_campaign_noise'][0]['excluded_because']);
        $this->assertSame(1, $out['single_campaign_noise'][0]['campaigns']);
    }

    public function test_two_occurrences_two_campaigns_is_noise_count_threshold(): void
    {
        $distiller = new AtlasLoopV3MetaStrategyDistiller($this->ledger([
            'campA' => [$this->converged()],
            'campB' => [$this->converged()],
        ]));

        $out = $distiller->distill(['campA', 'campB']);

        $this->assertSame([], $out['strategies']);
        $this->assertCount(1, $out['single_campaign_noise']);
        $this->assertSame('converge', $out['single_campaign_noise'][0]['kind']);
        $this->assertSame(2, $out['single_campaign_noise'][0]['count']);
        $this->assertSame('insufficient_cross_campaign_evidence', $out['single_campaign_noise'][0]['excluded_because']);
    }

    public function test_empty_input(): void
    {
        $out = (new AtlasLoopV3MetaStrategyDistiller($this->ledger([])))->distill([]);

        $this->assertSame([], $out['strategies']);
        $this->assertSame([], $out['single_campaign_noise']);
    }

    public function test_is_deterministic(): void
    {
        $ledgerData = [
            'campA' => [$this->parked(), $this->parked(), $this->converged()],
            'campB' => [$this->parked(), $this->converged(), $this->converged()],
            'campC' => [$this->converged()],
        ];

        $a = (new AtlasLoopV3MetaStrategyDistiller($this->ledger($ledgerData)))->distill(['campA', 'campB', 'campC']);
        $b = (new AtlasLoopV3MetaStrategyDistiller($this->ledger($ledgerData)))->distill(['campA', 'campB', 'campC']);

        $this->assertSame(json_encode($a), json_encode($b));
        // avoid: 3 across 2 campaigns ⇒ promoted; converge: 4 across 3 campaigns ⇒ promoted; ordered by count DESC.
        $this->assertSame(['converge', 'avoid'], array_column($a['strategies'], 'kind'));
    }
}
