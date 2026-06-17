<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaTreeAccessor as Tree;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopInsightBackpropService as BP;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT T2 — deterministic insight backprop (pure aggregation; no LLM; advisory).
 */
final class AtlasLoopInsightBackpropServiceTest extends TestCase
{
    public function test_aggregate_folds_dedups_and_is_deterministic(): void
    {
        $a = BP::aggregate(['lesson one', 'lesson two'], null);
        $this->assertSame(['lesson one', 'lesson two'], $a['distilled']);

        // dedup against existing + idempotent
        $b = BP::aggregate(['lesson two', 'lesson three'], $a);
        $this->assertSame(['lesson one', 'lesson two', 'lesson three'], $b['distilled']);

        // deterministic: same inputs -> same output
        $this->assertSame($b, BP::aggregate(['lesson two', 'lesson three'], $a));
    }

    public function test_aggregate_is_bounded(): void
    {
        $existing = null;
        for ($i = 0; $i < 30; $i++) {
            $existing = BP::aggregate(["lesson {$i}"], $existing);
        }
        $this->assertLessThanOrEqual(12, count($existing['distilled']));
        // keeps the most recent
        $this->assertContains('lesson 29', $existing['distilled']);
        $this->assertNotContains('lesson 0', $existing['distilled']);
    }

    public function test_lessons_of_merges_pruned_and_distilled(): void
    {
        $insight = ['pruned_lessons' => ['[Pruned: a]'], 'distilled' => ['b', 'a']];
        $out = BP::lessonsOf($insight);
        $this->assertContains('[Pruned: a]', $out);
        $this->assertContains('b', $out);
        // de-duplicated
        $this->assertSame(count($out), count(array_unique($out)));
    }

    public function test_kind_for_depth_types_direction_vs_implementation(): void
    {
        $this->assertSame(Tree::KIND_DIRECTION, BP::kindForDepth(1));
        $this->assertSame(Tree::KIND_IMPLEMENTATION, BP::kindForDepth(2));
        $this->assertSame(Tree::KIND_IMPLEMENTATION, BP::kindForDepth(5));
    }
}
