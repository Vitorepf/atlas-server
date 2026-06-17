<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopJObjective;
use Tests\TestCase;

/**
 * ARBOR-GRAFT J1 — the J objective is a faithful, read-only projection of the frozen acceptance contract.
 */
class AtlasLoopJObjectiveTest extends TestCase
{
    private function sampleAcceptance(): array
    {
        return [
            'commands' => ['./vendor/bin/phpunit --filter Foo'],
            'allowed_globs' => ['app/Foo.php'],
            'frozen_globs' => ['tests/FooTest.php', 'tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'revert_recheck' => true,
            'acceptance_hash' => 'deadbeef',
        ];
    }

    public function test_projects_every_field_from_acceptance(): void
    {
        $j = AtlasLoopJObjective::fromAcceptance($this->sampleAcceptance());

        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_GATE, $j->metricKind);
        $this->assertSame('pass', $j->metricDirection);          // gate => implicit "pass"
        $this->assertNull($j->metricPattern);
        $this->assertTrue($j->correctnessFloor);                 // revert_recheck present
        $this->assertTrue($j->holdout['frozen']);                // frozen_globs present
        $this->assertNull($j->transferSlice);                    // J2 not yet
        $this->assertSame('deadbeef', $j->acceptanceHash);
        $this->assertSame('red_on_baseline', $j->baseline);
    }

    public function test_weakened_contract_is_surfaced_by_the_projection(): void
    {
        // Drop the correctness floor (revert_recheck) => the projection honestly reports it as absent.
        $weak = $this->sampleAcceptance();
        unset($weak['revert_recheck']);

        $this->assertFalse(AtlasLoopJObjective::fromAcceptance($weak)->correctnessFloor);

        // Empty frozen surface => holdout.frozen false.
        $weak2 = $this->sampleAcceptance();
        $weak2['frozen_globs'] = [];
        $this->assertFalse(AtlasLoopJObjective::fromAcceptance($weak2)->holdout['frozen']);
    }

    public function test_numeric_metric_projects_direction_and_pattern(): void
    {
        $acc = $this->sampleAcceptance();
        $acc['metric_kind'] = 'numeric';
        $acc['metric_direction'] = 'minimize';
        $acc['metric_pattern'] = 'score:\\s*([0-9.]+)';

        $j = AtlasLoopJObjective::fromAcceptance($acc);
        $this->assertSame('numeric', $j->metricKind);
        $this->assertSame('minimize', $j->metricDirection);
        $this->assertSame('score:\\s*([0-9.]+)', $j->metricPattern);
    }

    public function test_toarray_round_trips_the_shape(): void
    {
        $arr = AtlasLoopJObjective::fromAcceptance($this->sampleAcceptance())->toArray();
        foreach (['metric_kind', 'metric_direction', 'baseline', 'correctness_floor', 'holdout', 'acceptance_hash'] as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
    }
}
