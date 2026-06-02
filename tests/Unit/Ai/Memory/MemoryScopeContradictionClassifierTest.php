<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Memory;

use App\Services\Ai\Memory\MemoryScopeContradictionClassifier;
use Tests\TestCase;

final class MemoryScopeContradictionClassifierTest extends TestCase
{
    private MemoryScopeContradictionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new MemoryScopeContradictionClassifier();
    }

    public function testGlobalAffirmVsTaskNegateHeuristicIsLegitimateOverrideWithoutEscalation(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 3,
                'polarity' => 'affirm',
                'memory_type' => 'heuristic',
            ],
            [
                'key' => 'deploy_window',
                'scope_rank' => 0,
                'polarity' => 'negate',
                'memory_type' => 'heuristic',
            ],
        );

        $this->assertSame('atlas.memory.scope_contradiction.v1', $result['schema_version']);
        $this->assertSame('legitimate_scope_override', $result['kind']);
        $this->assertTrue($result['override_legitimate']);
        $this->assertFalse($result['escalate']);
        $this->assertSame(['R4:legitimate_scope_override'], $result['reasons']);
    }

    public function testGlobalAffirmVsTaskNegatePolicyEscalates(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 3,
                'polarity' => 'affirm',
                'memory_type' => 'policy',
            ],
            [
                'key' => 'deploy_window',
                'scope_rank' => 0,
                'polarity' => 'negate',
                'memory_type' => 'heuristic',
            ],
        );

        $this->assertSame('legitimate_scope_override', $result['kind']);
        $this->assertTrue($result['override_legitimate']);
        $this->assertTrue($result['escalate']);
        $this->assertSame(['R4:legitimate_scope_override', 'R6:escalate'], $result['reasons']);
    }

    public function testSameKeyOppositePolarityEqualScopeRankIsDirectContradictionThatEscalates(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'retry_policy',
                'scope_rank' => 1,
                'polarity' => 'affirm',
                'memory_type' => 'heuristic',
            ],
            [
                'key' => 'retry_policy',
                'scope_rank' => 1,
                'polarity' => 'negate',
                'memory_type' => 'heuristic',
            ],
        );

        $this->assertSame('direct_scope_contradiction', $result['kind']);
        $this->assertFalse($result['override_legitimate']);
        $this->assertTrue($result['escalate']);
        $this->assertSame(['R5:direct_scope_contradiction', 'R6:escalate'], $result['reasons']);
    }

    public function testDifferentKeysAreUnrelated(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 3,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
            [
                'key' => 'cache_ttl',
                'scope_rank' => 0,
                'polarity' => 'negate',
                'memory_type' => 'heuristic',
            ],
        );

        $this->assertSame('unrelated', $result['kind']);
        $this->assertFalse($result['override_legitimate']);
        $this->assertFalse($result['escalate']);
        $this->assertSame(['R1:unrelated'], $result['reasons']);
    }

    public function testSameKeySamePolarityDifferentScopeRankIsScopeRedundant(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 3,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
            [
                'key' => 'deploy_window',
                'scope_rank' => 1,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
        );

        $this->assertSame('scope_redundant', $result['kind']);
        $this->assertTrue($result['override_legitimate']);
        $this->assertFalse($result['escalate']);
        $this->assertSame(['R3:scope_redundant'], $result['reasons']);
    }

    public function testSameKeySamePolarityEqualScopeRankIsNoContradiction(): void
    {
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 2,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
            [
                'key' => 'deploy_window',
                'scope_rank' => 2,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
        );

        $this->assertSame('no_contradiction', $result['kind']);
        $this->assertFalse($result['override_legitimate']);
        $this->assertFalse($result['escalate']);
        $this->assertSame(['R2:no_contradiction'], $result['reasons']);
    }

    public function testOppositePolarityWithNarrowerRankAboveBroaderFallsThroughToDirectContradiction(): void
    {
        // broader is the exception (narrow scope), narrower is the wide
        // contradicting rule: narrower.scope_rank (3) > broader.scope_rank (0)
        // falls through R4/R5 to direct_scope_contradiction.
        $result = $this->classifier->classify(
            [
                'key' => 'deploy_window',
                'scope_rank' => 0,
                'polarity' => 'affirm',
                'memory_type' => 'decision',
            ],
            [
                'key' => 'deploy_window',
                'scope_rank' => 3,
                'polarity' => 'negate',
                'memory_type' => 'heuristic',
            ],
        );

        $this->assertSame('direct_scope_contradiction', $result['kind']);
        $this->assertFalse($result['override_legitimate']);
        $this->assertTrue($result['escalate']);
        $this->assertSame(['R5:direct_scope_contradiction', 'R6:escalate'], $result['reasons']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $broader = [
            'key' => 'deploy_window',
            'scope_rank' => 3,
            'polarity' => 'affirm',
            'memory_type' => 'policy',
        ];
        $narrower = [
            'key' => 'deploy_window',
            'scope_rank' => 0,
            'polarity' => 'negate',
            'memory_type' => 'heuristic',
        ];

        $first = $this->classifier->classify($broader, $narrower);
        $second = $this->classifier->classify($broader, $narrower);

        $this->assertSame($first, $second);
    }
}
