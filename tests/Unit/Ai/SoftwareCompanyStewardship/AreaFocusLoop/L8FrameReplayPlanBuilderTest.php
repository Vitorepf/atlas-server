<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8FrameReplayPlanBuilder;
use PHPUnit\Framework\TestCase;

final class L8FrameReplayPlanBuilderTest extends TestCase
{
    private L8FrameReplayPlanBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L8FrameReplayPlanBuilder();
    }

    public function testHundredRealObrasAcrossBucketsProduceReadyPlan(): void
    {
        $obraRefs = $this->realObras(120, ['commerce', 'marketing', 'finance', 'cyber']);

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-001'],
            $obraRefs,
        );

        $this->assertSame('atlas.aaeos.l8.frame_replay_plan.v1', $result['schema_version']);
        $this->assertTrue($result['replay_ready']);
        $this->assertSame([], $result['blockers']);

        $plan = $result['replay_plan'];

        // obras_replayed_count is the real-obra count and clears the >=100 gate.
        $this->assertSame(120, $plan['obras_replayed_count']);
        $this->assertGreaterThanOrEqual(100, $plan['obras_replayed_count']);

        // diversity_buckets is a sorted, de-duplicated list<string>.
        $this->assertSame(['commerce', 'cyber', 'finance', 'marketing'], $plan['diversity_buckets']);

        // regression_metrics computed: clean replay, zero regressions.
        $this->assertSame(0, $plan['regression_metrics']['regression_observed_count']);
        $this->assertSame(120, $plan['regression_metrics']['clean_replay_count']);
        $this->assertSame(0.0, $plan['regression_metrics']['regression_rate']);

        $this->assertSame(120, count($result['real_obra_refs']));
        $this->assertSame(100, $result['min_real_obras']);
    }

    public function testFewerThanHundredRealObrasBlocks(): void
    {
        $obraRefs = $this->realObras(99, ['commerce', 'marketing']);

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-002'],
            $obraRefs,
        );

        $this->assertFalse($result['replay_ready']);
        $this->assertSame(99, $result['replay_plan']['obras_replayed_count']);
        $this->assertContains('insufficient_real_obras', $result['blockers']);
        $this->assertNotContains('synthetic_only_replay_blocked', $result['blockers']);
    }

    public function testSyntheticOnlyReplayBlocks(): void
    {
        $obraRefs = [];
        for ($i = 0; $i < 150; $i++) {
            $obraRefs[] = [
                'obra_id' => 'synthetic:fixture-'.$i,
                'synthetic' => true,
                'bucket' => 'commerce',
            ];
        }

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-003'],
            $obraRefs,
        );

        $this->assertFalse($result['replay_ready']);
        $this->assertSame(0, $result['replay_plan']['obras_replayed_count']);
        $this->assertContains('synthetic_only_replay_blocked', $result['blockers']);
        // Synthetic-only also fails the count floor.
        $this->assertContains('insufficient_real_obras', $result['blockers']);
        $this->assertSame([], $result['replay_plan']['diversity_buckets']);
    }

    public function testSyntheticEntriesAreExcludedFromRealCount(): void
    {
        // 110 real obras plus 40 synthetic ones mixed in; only the real ones count.
        $obraRefs = $this->realObras(110, ['commerce', 'finance']);
        for ($i = 0; $i < 40; $i++) {
            $obraRefs[] = [
                'obra_id' => 'synthetic:s-'.$i,
                'source' => 'synthetic',
                'bucket' => 'should-not-appear',
            ];
        }

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-004'],
            $obraRefs,
        );

        $this->assertTrue($result['replay_ready']);
        $this->assertSame(110, $result['replay_plan']['obras_replayed_count']);
        $this->assertSame(['commerce', 'finance'], $result['replay_plan']['diversity_buckets']);
        $this->assertNotContains('should-not-appear', $result['replay_plan']['diversity_buckets']);
    }

    public function testObservedRegressionBlocksPromotion(): void
    {
        $obraRefs = $this->realObras(130, ['commerce', 'marketing']);
        // Flip three real obras to a regressed replay outcome.
        $obraRefs[5]['regression'] = true;
        $obraRefs[40]['status'] = 'regressed';
        $obraRefs[90]['regression'] = true;

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-005'],
            $obraRefs,
        );

        $this->assertFalse($result['replay_ready']);
        $this->assertContains('regressions_observed', $result['blockers']);
        $this->assertNotContains('insufficient_real_obras', $result['blockers']);

        $metrics = $result['replay_plan']['regression_metrics'];
        $this->assertSame(3, $metrics['regression_observed_count']);
        $this->assertSame(127, $metrics['clean_replay_count']);
        $this->assertEqualsWithDelta(3 / 130, $metrics['regression_rate'], 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $metrics['regression_rate']);
        $this->assertLessThanOrEqual(1.0, $metrics['regression_rate']);
    }

    public function testMissingProposalIdBlocks(): void
    {
        $obraRefs = $this->realObras(120, ['commerce', 'marketing']);

        $result = $this->builder->build([], $obraRefs);

        $this->assertFalse($result['replay_ready']);
        $this->assertContains('proposal_id_missing', $result['blockers']);
        // The replay itself is otherwise fine — count still clears the floor.
        $this->assertSame(120, $result['replay_plan']['obras_replayed_count']);
        $this->assertNotContains('insufficient_real_obras', $result['blockers']);
    }

    public function testDuplicateObraIdsCountOnce(): void
    {
        $obraRefs = $this->realObras(120, ['commerce']);
        // Re-add the first 30 ids as duplicates; distinct real count stays 120.
        for ($i = 0; $i < 30; $i++) {
            $obraRefs[] = [
                'obra_id' => 'obra-'.$i,
                'real' => true,
                'bucket' => 'commerce',
            ];
        }

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-006'],
            $obraRefs,
        );

        $this->assertSame(120, $result['replay_plan']['obras_replayed_count']);
        $this->assertTrue($result['replay_ready']);
    }

    public function testDiversityBucketsIsSequentialStringList(): void
    {
        // Buckets supplied out of order and duplicated; output must be a clean,
        // zero-indexed, sorted list<string> (no int-key coercion, no holes).
        $obraRefs = $this->realObras(105, ['zeta', 'alpha', 'mike', 'alpha', 'zeta']);

        $result = $this->builder->build(
            ['proposal_id' => 'redesign-007'],
            $obraRefs,
        );

        $buckets = $result['replay_plan']['diversity_buckets'];

        $this->assertSame(['alpha', 'mike', 'zeta'], $buckets);
        $this->assertSame(array_values($buckets), $buckets);
        $this->assertSame(range(0, count($buckets) - 1), array_keys($buckets));
        foreach ($buckets as $bucket) {
            $this->assertIsString($bucket);
        }
    }

    public function testNumericObraIdsAndBucketsStayStringList(): void
    {
        // Adversarial: Obra ids and bucket labels are integer-like strings
        // (e.g. database primary keys / numeric domain codes). PHP would coerce
        // such array keys back to int; the output must still be a list<string>.
        $obraRefs = [];
        for ($i = 0; $i < 100; $i++) {
            $obraRefs[] = [
                'obra_id' => (string) (1000 + $i),
                'real' => true,
                'bucket' => (string) ($i % 3),
            ];
        }

        $result = $this->builder->build(['proposal_id' => 'redesign-009'], $obraRefs);

        $this->assertTrue($result['replay_ready']);
        $this->assertSame(100, $result['replay_plan']['obras_replayed_count']);

        $realRefs = $result['real_obra_refs'];
        $this->assertSame(range(0, count($realRefs) - 1), array_keys($realRefs));
        foreach ($realRefs as $ref) {
            $this->assertIsString($ref);
        }
        $this->assertSame('1000', $realRefs[0]);

        $buckets = $result['replay_plan']['diversity_buckets'];
        $this->assertSame(['0', '1', '2'], $buckets);
        foreach ($buckets as $bucket) {
            $this->assertIsString($bucket);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proposal = ['proposal_id' => 'redesign-008'];
        $obraRefs = $this->realObras(118, ['commerce', 'finance', 'marketing']);

        $first = $this->builder->build($proposal, $obraRefs);
        $second = $this->builder->build($proposal, $obraRefs);

        $this->assertSame($first, $second);
    }

    /**
     * Build N deterministic real Obra refs spread round-robin across the given
     * buckets. Pure helper — no randomness, stable ordering.
     *
     * @param list<string> $buckets
     *
     * @return list<array<string,mixed>>
     */
    private function realObras(int $count, array $buckets): array
    {
        $obras = [];
        $bucketCount = count($buckets);

        for ($i = 0; $i < $count; $i++) {
            $obras[] = [
                'obra_id' => 'obra-'.$i,
                'real' => true,
                'source' => 'production',
                'bucket' => $buckets[$i % $bucketCount],
            ];
        }

        return $obras;
    }
}
