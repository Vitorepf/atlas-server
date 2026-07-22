<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Antifragile;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopMetaObjectiveProposer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopMetaObjectiveProposer is a PROPOSE-ONLY, pétreo-respecting, deterministic proposer: it only
 * proposes for CREDITED shapes, never targets a forbidden self-target, orders by attribution-derived leverage,
 * and carries no merge/self-approval action.
 */
final class AtlasLoopMetaObjectiveProposerTest extends TestCase
{
    private const JUDGE = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';

    private function proposer(): AtlasLoopMetaObjectiveProposer
    {
        return new AtlasLoopMetaObjectiveProposer;
    }

    /** @return array<string,mixed> */
    private function shape(string $token, string $area, float $wilson, float $mean, bool $credited = true): array
    {
        return ['shape_token' => $token, 'target_area' => $area, 'wilson_lower_bound' => $wilson, 'mean_delta' => $mean, 'samples' => 6, 'credited' => $credited];
    }

    public function test_never_proposes_against_a_forbidden_self_target(): void
    {
        $result = $this->proposer()->propose(
            [
                $this->shape('s_judge', self::JUDGE, 0.8, 5.0),       // credited but pétreo ⇒ dropped
                $this->shape('s_ok', 'app/Services/Ai/MarketingDomain', 0.6, 3.0),
            ],
            [self::JUDGE],
        );

        $areas = array_column($result['proposals'], 'target_area');
        $this->assertNotContains(self::JUDGE, $areas, 'a pétreo target is NEVER proposed');
        $this->assertContains('app/Services/Ai/MarketingDomain', $areas);
        $this->assertSame(
            [['target_area' => self::JUDGE, 'reason' => 'forbidden_self_target']],
            $result['dropped'],
            'the forbidden target is dropped with a recorded reason',
        );
    }

    public function test_proposals_ordered_by_attribution_leverage(): void
    {
        $result = $this->proposer()->propose(
            [
                $this->shape('s_low', 'app/Area/Low', 0.5, 2.0),   // leverage 1.0
                $this->shape('s_high', 'app/Area/High', 0.9, 8.0), // leverage 7.2
                $this->shape('s_mid', 'app/Area/Mid', 0.6, 5.0),   // leverage 3.0
            ],
            [],
        );

        $this->assertSame(
            ['app/Area/High', 'app/Area/Mid', 'app/Area/Low'],
            array_column($result['proposals'], 'target_area'),
            'ordered by expected_leverage (wilson*mean) descending',
        );
        $this->assertSame(7.2, $result['proposals'][0]['expected_leverage']);
    }

    public function test_uncredited_shape_yields_no_proposal_and_is_deterministic(): void
    {
        $deliveries = [
            $this->shape('s_thin', 'app/Area/Thin', 0.0, 9.0, credited: false), // not credited ⇒ ignored
            $this->shape('s_ok', 'app/Area/Ok', 0.7, 4.0),
        ];

        $run1 = $this->proposer()->propose($deliveries, []);
        $run2 = $this->proposer()->propose($deliveries, []);
        $this->assertSame(json_encode($run1), json_encode($run2), 'deterministic');

        $areas = array_column($run1['proposals'], 'target_area');
        $this->assertSame(['app/Area/Ok'], $areas, 'only the credited shape produces a proposal');
    }

    public function test_source_has_no_merge_or_self_approval_path(): void
    {
        $file = (new \ReflectionClass(AtlasLoopMetaObjectiveProposer::class))->getFileName();
        $source = (string) file_get_contents((string) $file);

        foreach (['merge', 'approve', 'autoMerge', 'auto_applied', '->apply(', 'Storage::put', 'DB::insert'] as $token) {
            $this->assertStringNotContainsString($token, $source, "propose-only: source must not contain '{$token}'");
        }
    }
}
