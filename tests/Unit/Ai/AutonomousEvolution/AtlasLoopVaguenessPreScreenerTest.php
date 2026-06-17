<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopVaguenessPreScreener;
use PHPUnit\Framework\TestCase;

/**
 * ACDE U4 — the deterministic vagueness pre-screener. CONSERVATIVE: a goal is vague ONLY when it carries no
 * concrete anchor (path / symbol / member-ref / quoted id). Pure => hang-free.
 */
final class AtlasLoopVaguenessPreScreenerTest extends TestCase
{
    private function s(): AtlasLoopVaguenessPreScreener
    {
        return new AtlasLoopVaguenessPreScreener;
    }

    public function test_zero_anchor_goals_are_vague(): void
    {
        foreach (['improve robustness', 'make it better and cleaner please', 'optimize the system for speed'] as $goal) {
            $r = $this->s()->screen($goal);
            $this->assertTrue($r['vague'], $goal);
            $this->assertContains('no_concrete_anchor', $r['reasons']);
            $this->assertGreaterThan(0.0, $r['score']);
        }
    }

    public function test_anchored_goals_are_never_vague(): void
    {
        $goals = [
            'simplify Foo::bar to reduce its complexity',
            'fix the off-by-one in src/Auth/Login.php',
            'extract a helper out of AtlasLoopThing',
            "rename the 'userId' field everywhere",
            'inline $repo->save() at the call site',
        ];
        foreach ($goals as $goal) {
            $r = $this->s()->screen($goal);
            $this->assertFalse($r['vague'], $goal.' has a concrete anchor');
            $this->assertSame(0.0, $r['score']);
        }
    }

    public function test_terse_but_concrete_is_not_vague_even_if_short(): void
    {
        // 2 words but a concrete member-ref anchor => NOT vague (too_short is colour, not the trigger).
        $r = $this->s()->screen('fix Foo::bar');
        $this->assertFalse($r['vague']);
        $this->assertContains('too_short', $r['reasons'], 'too_short is recorded as colour');
    }
}
