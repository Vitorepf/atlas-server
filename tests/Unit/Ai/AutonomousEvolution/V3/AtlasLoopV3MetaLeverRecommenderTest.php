<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3MetaLeverRecommender;
use PHPUnit\Framework\TestCase;

/**
 * Proves the meta-lever recommender over the real AtlasLoopLeverImpactMeter: recommends only improved +
 * non-starved levers, ranks deterministically, rejects with named reasons, and is byte-stable.
 */
final class AtlasLoopV3MetaLeverRecommenderTest extends TestCase
{
    private function recommender(): AtlasLoopV3MetaLeverRecommender
    {
        return new AtlasLoopV3MetaLeverRecommender;
    }

    /** @return array{before:array<string,int>, after:array<string,int>} */
    private function reading(int $admB, int $genB, int $certB, int $attB, int $admA, int $genA, int $certA, int $attA): array
    {
        return [
            'before' => ['admitted' => $admB, 'generated' => $genB, 'certified' => $certB, 'attempted' => $attB],
            'after' => ['admitted' => $admA, 'generated' => $genA, 'certified' => $certA, 'attempted' => $attA],
        ];
    }

    public function test_recommends_an_improved_non_starved_lever(): void
    {
        // adm 1.0->1.0 (no starvation); conv 0.1->0.5 (improved).
        $out = $this->recommender()->recommend(['lever_a' => $this->reading(10, 10, 1, 10, 10, 10, 5, 10)]);

        $this->assertSame('lever_a', $out['recommend']);
        $this->assertCount(1, $out['ranked']);
        $this->assertSame([], $out['rejected']);
        $this->assertSame('atlas.loop.v3.meta_lever_recommender.v1', $out['schema']);
    }

    public function test_starved_lever_is_rejected_even_if_conversion_rose(): void
    {
        // adm 1.0->0.2 (<0.5 ⇒ starvation) while conv 0.1->0.8 rose.
        $out = $this->recommender()->recommend(['lever_s' => $this->reading(10, 10, 1, 10, 2, 10, 8, 10)]);

        $this->assertNull($out['recommend']);
        $this->assertSame([], $out['ranked']);
        $this->assertSame('lever_s', $out['rejected'][0]['lever']);
        $this->assertSame('starved', $out['rejected'][0]['reason']);
    }

    public function test_higher_conversion_delta_wins(): void
    {
        $out = $this->recommender()->recommend([
            'lo' => $this->reading(10, 10, 1, 10, 10, 10, 3, 10), // conv 0.1->0.3 (+0.2)
            'hi' => $this->reading(10, 10, 1, 10, 10, 10, 5, 10), // conv 0.1->0.5 (+0.4)
        ]);

        $this->assertSame('hi', $out['recommend']);
        $this->assertSame(['hi', 'lo'], array_column($out['ranked'], 'lever'));
    }

    public function test_tie_on_conversion_broken_by_admission_delta(): void
    {
        // both conv +0.3; X admission +0.1, Y admission +0.2 ⇒ Y wins.
        $out = $this->recommender()->recommend([
            'x' => $this->reading(8, 10, 1, 10, 9, 10, 4, 10),  // adm 0.8->0.9 (+0.1), conv 0.1->0.4
            'y' => $this->reading(7, 10, 1, 10, 9, 10, 4, 10),  // adm 0.7->0.9 (+0.2), conv 0.1->0.4
        ]);

        $this->assertSame('y', $out['recommend']);
        $this->assertSame(['y', 'x'], array_column($out['ranked'], 'lever'));
    }

    public function test_all_flat_recommends_null_with_flat_reasons(): void
    {
        $out = $this->recommender()->recommend(['f' => $this->reading(10, 10, 1, 10, 10, 10, 1, 10)]); // conv 0.1->0.1

        $this->assertNull($out['recommend']);
        $this->assertSame([], $out['ranked']);
        $this->assertSame('flat', $out['rejected'][0]['reason']);
    }

    public function test_regressed_lever_excluded_with_reason(): void
    {
        $out = $this->recommender()->recommend(['r' => $this->reading(10, 10, 5, 10, 10, 10, 1, 10)]); // conv 0.5->0.1

        $this->assertNull($out['recommend']);
        $this->assertSame('regressed', $out['rejected'][0]['reason']);
    }

    public function test_empty_input(): void
    {
        $out = $this->recommender()->recommend([]);

        $this->assertNull($out['recommend']);
        $this->assertSame([], $out['ranked']);
        $this->assertSame([], $out['rejected']);
    }

    public function test_is_deterministic(): void
    {
        $input = [
            'hi' => $this->reading(10, 10, 1, 10, 10, 10, 5, 10),
            'lo' => $this->reading(10, 10, 1, 10, 10, 10, 3, 10),
            'bad' => $this->reading(10, 10, 5, 10, 10, 10, 1, 10),
        ];

        $a = $this->recommender()->recommend($input);
        $b = $this->recommender()->recommend($input);

        $this->assertSame($a, $b);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
