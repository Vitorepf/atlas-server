<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Lever 5 — the SPEC-AMPLIFICATION gate (the "large complex implementation, correct" enabler).
 *
 * For a feature, the frozen acceptance test IS the spec. A single thin test UNDER-specifies a complex
 * feature: the loop passes it while missing the real intent (spec-gaming) — "it passed the one test I was
 * given" is NOT "it satisfies the specified behaviour". This gate measures the RICHNESS of the acceptance
 * test (distinct test cases + assertions) and refuses a feature whose spec is too thin to pin a complex
 * behaviour, so the spec must be AMPLIFIED (boundary / error / idempotency cases) before any provider
 * budget is spent implementing against it.
 *
 * Pure + deterministic (static analysis over the test source — no provider, no execution). Fail-OPEN when
 * the floors are 0 (OFF) so it is byte-identical until armed.
 */
final class AtlasLoopSpecAmplificationGate
{
    /**
     * @return array{rich:bool, assertions:int, test_methods:int, min_assertions:int, min_methods:int, reason:?string}
     */
    public function assess(string $testSource, int $minAssertions = 0, int $minMethods = 0): array
    {
        $minAssertions = max(0, $minAssertions);
        $minMethods = max(0, $minMethods);

        $assertions = $this->countAssertions($testSource);
        $methods = $this->countTestMethods($testSource);

        // OFF (both floors 0) => never gate (byte-identical until armed).
        if ($minAssertions === 0 && $minMethods === 0) {
            return $this->result(true, $assertions, $methods, $minAssertions, $minMethods, null);
        }

        $gaps = [];
        if ($assertions < $minAssertions) {
            $gaps[] = 'assertions:'.$assertions.'<'.$minAssertions;
        }
        if ($methods < $minMethods) {
            $gaps[] = 'test_methods:'.$methods.'<'.$minMethods;
        }
        $rich = $gaps === [];

        return $this->result($rich, $assertions, $methods, $minAssertions, $minMethods, $rich ? null : 'spec_too_thin:'.implode(',', $gaps));
    }

    private function countAssertions(string $src): int
    {
        // PHPUnit assertions: $this->assert*/static::assert*/self::assert*/assertThat, plus expectException.
        return preg_match_all('/(?:\$this->|self::|static::)assert\w*\s*\(|->expectException\s*\(/', $src)
            + preg_match_all('/\bassertThat\s*\(/', $src);
    }

    private function countTestMethods(string $src): int
    {
        // test* methods + #[Test]/@test annotated methods.
        $byName = preg_match_all('/function\s+test[A-Z_0-9]\w*\s*\(/', $src);
        $byAttr = preg_match_all('/#\[\s*Test\s*\]|@test\b/', $src);

        return $byName + $byAttr;
    }

    /**
     * @return array{rich:bool, assertions:int, test_methods:int, min_assertions:int, min_methods:int, reason:?string}
     */
    private function result(bool $rich, int $assertions, int $methods, int $minAssertions, int $minMethods, ?string $reason): array
    {
        return [
            'rich' => $rich,
            'assertions' => $assertions,
            'test_methods' => $methods,
            'min_assertions' => $minAssertions,
            'min_methods' => $minMethods,
            'reason' => $reason,
        ];
    }
}
