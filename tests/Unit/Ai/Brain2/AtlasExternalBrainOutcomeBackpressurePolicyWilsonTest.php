<?php

declare(strict_types=1);

namespace Tests\Unit\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeBackpressurePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves that evaluate() gates RECOMMENDATION_PROMOTE on the Wilson score
 * lower bound instead of the raw success rate, so thin-evidence families
 * (1/1, 3/3) no longer promote despite raw 100% success.
 *
 * BEFORE: 1/1 → successRate=1.0 >= 0.75 → promote + safe_to_promote=true
 * AFTER:  1/1 → Wilson LB ≈ 0.21 < 0.75 → continue + safe_to_promote=true
 *         3/3 → Wilson LB ≈ 0.44 < 0.75 → continue + safe_to_promote=true
 *         30/33 → Wilson LB ≈ 0.78 >= 0.75 → promote + safe_to_promote=true
 */
final class AtlasExternalBrainOutcomeBackpressurePolicyWilsonTest extends TestCase
{
    private function policy(): AtlasExternalBrainOutcomeBackpressurePolicy
    {
        return new AtlasExternalBrainOutcomeBackpressurePolicy;
    }

    /** @param list<string> $types */
    private function evaluate(array $types): array
    {
        return $this->policy()->evaluate([
            'task_family' => 'test',
            'outcome_history' => array_map(static fn (string $t): array => ['outcome' => $t], $types),
        ]);
    }

    public function test_single_success_no_longer_promotes(): void
    {
        // 1/1 — raw successRate=1.0 but Wilson LB ≈ 0.21 < 0.75
        $r = $this->evaluate(['success']);

        $this->assertSame(
            AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE,
            $r['recommendation'],
            '1/1 with Wilson LB ≈ 0.21 must not promote despite 100% raw rate',
        );
        $this->assertTrue($r['safe_to_promote'],
            'continue is still safe_to_promote=true');
    }

    public function test_three_success_no_longer_promotes(): void
    {
        // 3/3 — raw successRate=1.0 but Wilson LB ≈ 0.44 < 0.75
        $r = $this->evaluate(['success', 'success', 'success']);

        $this->assertSame(
            AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE,
            $r['recommendation'],
            '3/3 with Wilson LB ≈ 0.44 must not promote despite 100% raw rate',
        );
    }

    public function test_thirty_of_thirty_three_still_promotes(): void
    {
        // 30/33 — Wilson LB ≈ 0.78 >= 0.75
        $types = array_merge(
            array_fill(0, 30, 'success'),
            ['give_back', 'give_back', 'give_back'],
        );
        $r = $this->evaluate($types);

        $this->assertSame(
            AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE,
            $r['recommendation'],
            '30/33 with Wilson LB ≈ 0.78 must still promote',
        );
        $this->assertTrue($r['safe_to_promote']);
    }
}
