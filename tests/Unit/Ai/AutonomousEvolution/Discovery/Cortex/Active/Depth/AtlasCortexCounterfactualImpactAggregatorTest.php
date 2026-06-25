<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualImpactAggregator;
use Tests\TestCase;

final class AtlasCortexCounterfactualImpactAggregatorTest extends TestCase
{
    private function walk(string $id, array $sitesAndKinds): array
    {
        $steps = [];
        foreach ($sitesAndKinds as [$site, $kind]) {
            $steps[] = ['site' => $site, 'mutation_kind' => $kind];
        }

        return ['walk_id' => $id, 'steps' => $steps];
    }

    public function test_flag_off_returns_empty_clusters(): void
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: false);
        $out = $agg->aggregate([$this->walk('w1', [['app/Foo.php', 'tighten']])]);

        $this->assertSame([], $out['clusters']);
        $this->assertSame('flag_off', $out['reason']);
    }

    public function test_three_walks_two_overlapping_sites_form_clusters(): void
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: true);
        $out = $agg->aggregate([
            $this->walk('w1', [['app/Foo.php', 'tighten'], ['app/Bar.php', 'rename']]),
            $this->walk('w2', [['app/Foo.php', 'add_guard'], ['app/Baz.php', 'tighten']]),
            $this->walk('w3', [['app/Bar.php', 'extract']]),
        ]);

        $clusters = $out['clusters'];
        $byId = array_column($clusters, null, 'site_id');
        $this->assertArrayHasKey('app/foo.php', $byId);
        $this->assertArrayHasKey('app/bar.php', $byId);
        $this->assertArrayNotHasKey('app/baz.php', $byId, 'a singleton site should not become a cluster');

        $this->assertSame(['w1', 'w2'], $byId['app/foo.php']['contributing_walk_ids']);
        $this->assertSame(['w1', 'w3'], $byId['app/bar.php']['contributing_walk_ids']);
        $this->assertSame(2, $byId['app/foo.php']['mutation_kind_count']);
        $this->assertSame(2, $byId['app/bar.php']['mutation_kind_count']);

        $json = (string) json_encode($out, JSON_UNESCAPED_SLASHES);
        foreach (['"score"', '"rank"', '"priority"', '"rating"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_canonical_site_predicate_treats_case_and_leading_slash_as_equivalent(): void
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: true);
        $out = $agg->aggregate([
            $this->walk('w1', [['app/Services/Foo.php', 'tighten']]),
            $this->walk('w2', [['/app/services/foo.php', 'rename']]),
        ]);

        $this->assertCount(1, $out['clusters']);
        $this->assertSame('app/services/foo.php', $out['clusters'][0]['site_id']);
        $this->assertSame(['w1', 'w2'], $out['clusters'][0]['contributing_walk_ids']);
    }

    public function test_singletons_are_not_clusters(): void
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: true);
        $out = $agg->aggregate([
            $this->walk('w1', [['app/Foo.php', 'tighten']]),
            $this->walk('w2', [['app/Bar.php', 'tighten']]),
        ]);

        $this->assertSame([], $out['clusters']);
    }

    public function test_empty_input_returns_empty_clusters(): void
    {
        $agg = new AtlasCortexCounterfactualImpactAggregator(enabled: true);
        $out = $agg->aggregate([]);

        $this->assertSame([], $out['clusters']);
    }
}
