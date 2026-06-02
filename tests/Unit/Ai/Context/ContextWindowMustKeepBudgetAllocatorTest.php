<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\ContextWindowMustKeepBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class ContextWindowMustKeepBudgetAllocatorTest extends TestCase
{
    private ContextWindowMustKeepBudgetAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new ContextWindowMustKeepBudgetAllocator();
    }

    public function testOverflowingMustKeepProducesDegradationPlanThatFitsBudget(): void
    {
        $result = $this->allocator->allocate(
            [
                ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 1200, 'priority' => 1.0, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 1000, 'priority' => 0.98, 'must_keep' => true],
                ['kind' => 'dod', 'ref' => 'dod:1', 'tokens' => 900, 'priority' => 0.96, 'must_keep' => true],
            ],
            2000,
        );

        $this->assertSame('atlas.context.must_keep_budget_allocation.v1', $result['schema_version']);
        $this->assertTrue($result['overflow']);
        $this->assertSame(2000, $result['token_budget']);
        $this->assertSame(3100, $result['must_keep_tokens_total']);
        $this->assertSame(1100, $result['deficit_tokens']);
        $this->assertTrue($result['degradation_required']);

        $byRef = $this->indexBy($result['included'], 'ref');

        // Highest-ranked must_keep is kept full.
        $this->assertArrayHasKey('decision:1', $byRef);
        $this->assertSame('kept_full', $byRef['decision:1']['disposition']);
        $this->assertSame(1200, $byRef['decision:1']['compression_target_tokens']);

        // Lowest-ranked dod is NOT kept_full: either flagged_for_compression or excluded overflow.
        $dodDisposition = $this->dispositionOf($result, 'dod:1');
        $this->assertNotSame('kept_full', $dodDisposition);
        $this->assertContains($dodDisposition, ['flagged_for_compression', 'overflow']);

        // Honest coverage == kept_full_count / must_keep_count.
        $keptFullCount = $this->keptFullMustKeepCount($result);
        $this->assertSame(1, $keptFullCount);
        $this->assertSame(round($keptFullCount / 3, 4), $result['must_keep_coverage']);
        $this->assertTrue($result['must_keep_coverage'] < 1.0);

        $this->assertContains('must_keep_coverage_below_one', $result['blockers']);

        // The plan actually fits: sum(compression_target over flagged) + sum(kept_full tokens) <= budget.
        $flaggedTargetsSum = 0;
        $keptFullTokensSum = 0;
        foreach ($result['included'] as $row) {
            if ($row['disposition'] === 'flagged_for_compression') {
                $flaggedTargetsSum += $row['compression_target_tokens'];
            }
            if ($row['disposition'] === 'kept_full' && $row['must_keep'] === true) {
                $keptFullTokensSum += $row['tokens'];
            }
        }
        $this->assertLessThanOrEqual($result['token_budget'], $flaggedTargetsSum + $keptFullTokensSum);

        // Every flagged segment carries a positive, recoverable compression target.
        foreach ($result['included'] as $row) {
            if ($row['disposition'] === 'flagged_for_compression') {
                $this->assertGreaterThan(0, $row['compression_target_tokens']);
                $this->assertLessThan($row['tokens'], $row['compression_target_tokens']);
            }
        }
    }

    public function testMustKeepTotalEqualToBudgetKeepsEverythingFullWithNoBlockers(): void
    {
        $result = $this->allocator->allocate(
            [
                ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 1200, 'priority' => 1.0, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 800, 'priority' => 0.9, 'must_keep' => true],
            ],
            2000,
        );

        $this->assertFalse($result['overflow']);
        $this->assertSame(0, $result['deficit_tokens']);
        $this->assertSame(2000, $result['must_keep_tokens_total']);
        $this->assertSame(1.0, $result['must_keep_coverage']);
        $this->assertSame([], $result['blockers']);
        $this->assertFalse($result['degradation_required']);

        foreach ($result['included'] as $row) {
            $this->assertSame('kept_full', $row['disposition']);
            $this->assertSame($row['tokens'], $row['compression_target_tokens']);
        }
        $this->assertSame([], $result['excluded']);
    }

    public function testZeroMustKeepReportsFullCoverageAndFitsOptionalGreedilyByPriority(): void
    {
        $result = $this->allocator->allocate(
            [
                ['kind' => 'memory', 'ref' => 'mem:low', 'tokens' => 600, 'priority' => 0.40, 'must_keep' => false],
                ['kind' => 'evidence', 'ref' => 'ev:high', 'tokens' => 600, 'priority' => 0.90, 'must_keep' => false],
                ['kind' => 'context', 'ref' => 'ctx:mid', 'tokens' => 600, 'priority' => 0.60, 'must_keep' => false],
            ],
            1000,
        );

        $this->assertSame(1.0, $result['must_keep_coverage']);
        $this->assertFalse($result['overflow']);
        $this->assertSame(0, $result['deficit_tokens']);
        $this->assertSame(0, $result['must_keep_tokens_total']);
        $this->assertSame([], $result['blockers']);

        // Greedy by priority desc: highest-priority optional fits first; the rest is trimmed.
        $includedRefs = array_column($result['included'], 'ref');
        $this->assertContains('ev:high', $includedRefs);
        $this->assertNotContains('mem:low', $includedRefs);

        $excludedByRef = $this->indexBy($result['excluded'], 'ref');
        $this->assertArrayHasKey('mem:low', $excludedByRef);
        $this->assertSame('budget_trim_optional', $excludedByRef['mem:low']['disposition']);
    }

    public function testUnrecoverableOverflowWhenKeptFullConsumesEntireBudget(): void
    {
        $result = $this->allocator->allocate(
            [
                ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 1000, 'priority' => 1.0, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 600, 'priority' => 0.9, 'must_keep' => true],
            ],
            1000,
        );

        $this->assertTrue($result['overflow']);
        $this->assertSame(600, $result['deficit_tokens']);
        $this->assertSame(1600, $result['must_keep_tokens_total']);
        $this->assertSame(round(1 / 2, 4), $result['must_keep_coverage']);

        // No leftover after kept_full -> the remaining must_keep cannot be compressed.
        $this->assertContains('must_keep_unrecoverable_overflow', $result['blockers']);
        $this->assertContains('must_keep_coverage_below_one', $result['blockers']);

        $excludedByRef = $this->indexBy($result['excluded'], 'ref');
        $this->assertArrayHasKey('blocker:1', $excludedByRef);
        $this->assertSame('overflow', $excludedByRef['blocker:1']['disposition']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $segments = [
            ['kind' => 'decision', 'ref' => 'decision:1', 'tokens' => 1200, 'priority' => 1.0, 'must_keep' => true],
            ['kind' => 'blocker', 'ref' => 'blocker:1', 'tokens' => 1000, 'priority' => 0.98, 'must_keep' => true],
            ['kind' => 'dod', 'ref' => 'dod:1', 'tokens' => 900, 'priority' => 0.96, 'must_keep' => true],
        ];

        $first = $this->allocator->allocate($segments, 2000);
        $second = $this->allocator->allocate($segments, 2000);

        $this->assertSame($first, $second);
    }

    /**
     * @param  list<array<string,mixed>>  $result
     */
    private function dispositionOf(array $result, string $ref): string
    {
        foreach ($result['included'] as $row) {
            if ($row['ref'] === $ref) {
                return (string) $row['disposition'];
            }
        }
        foreach ($result['excluded'] as $row) {
            if ($row['ref'] === $ref) {
                return (string) $row['disposition'];
            }
        }

        return 'absent';
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function keptFullMustKeepCount(array $result): int
    {
        $count = 0;
        foreach ($result['included'] as $row) {
            if ($row['disposition'] === 'kept_full' && $row['must_keep'] === true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array<string,mixed>>
     */
    private function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row[$key]] = $row;
        }

        return $out;
    }
}
