<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResearchContract as RC;
use Tests\TestCase;

/**
 * ARBOR-GRAFT J3 — the 5-component Research Contract VO (advisory; never gates).
 */
class AtlasLoopResearchContractTest extends TestCase
{
    private function acceptance(array $extra = []): array
    {
        return array_merge([
            'commands' => ['./vendor/bin/phpunit --filter Foo'],
            'allowed_globs' => ['app/Foo.php'],
            'frozen_globs' => ['tests/FooTest.php'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'revert_recheck' => true,
        ], $extra);
    }

    public function test_projects_all_five_components_and_is_complete(): void
    {
        $c = RC::fromAcceptance($this->acceptance(), ['ambition' => RC::AMBITION_REACH, 'scope' => RC::SCOPE_NOVELTY]);

        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_GATE, $c->metric->metricKind);
        $this->assertSame('red_on_baseline', $c->baseline);
        $this->assertSame(RC::AMBITION_REACH, $c->ambition);
        $this->assertSame(RC::SCOPE_NOVELTY, $c->scope);
        $this->assertSame(['tests/FooTest.php'], $c->hardConstraints['frozen_globs']);
        $this->assertTrue($c->isComplete());
        $this->assertSame([], $c->missingComponents());
    }

    public function test_unknown_scope_and_ambition_default_safely(): void
    {
        $c = RC::fromAcceptance($this->acceptance(), ['ambition' => 'nonsense', 'scope' => 'whatever']);
        $this->assertSame(RC::AMBITION_BEAT, $c->ambition);
        $this->assertSame(RC::SCOPE_MIXED, $c->scope);
    }

    public function test_missing_frozen_surface_makes_contract_incomplete(): void
    {
        $c = RC::fromAcceptance($this->acceptance(['frozen_globs' => []]));
        $this->assertFalse($c->isComplete());
        $this->assertContains('HARD_CONSTRAINTS', $c->missingComponents());
    }

    public function test_sealed_holdout_detection(): void
    {
        $this->assertFalse(RC::fromAcceptance($this->acceptance())->hasSealedHoldout());
        $this->assertTrue(RC::fromAcceptance($this->acceptance(['sealed_holdout' => ['tests/holdout/**']]))->hasSealedHoldout());
    }

    public function test_toarray_carries_all_components(): void
    {
        $arr = RC::fromAcceptance($this->acceptance())->toArray();
        foreach (['metric', 'baseline', 'ambition', 'scope', 'hard_constraints', 'complete', 'has_sealed_holdout'] as $k) {
            $this->assertArrayHasKey($k, $arr);
        }
    }
}
