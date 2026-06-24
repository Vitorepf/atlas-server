<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionPlanSemanticProver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasAaelExecutionPlanSemanticProverTest extends TestCase
{
    #[Test]
    public function it_reports_missing_objective_anchors_as_facts(): void
    {
        $prover = new AtlasAaelExecutionPlanSemanticProver;

        $proof = $prover->prove([
            'tasks' => [
                ['objective' => 'Implement anchor A only'],
            ],
        ], 'A B C');

        self::assertSame(['b', 'c'], $proof['unmet_objective_anchors']);
        self::assertSame(0.3333, $proof['objective_token_coverage_ratio']);
    }

    #[Test]
    public function it_exposes_the_fact_schema_without_goodhart_verdict_keys(): void
    {
        $prover = new AtlasAaelExecutionPlanSemanticProver;

        $proof = $prover->prove([
            'tasks' => [
                ['objective' => 'Touch anchor cache'],
                ['objective' => 'Review docs only'],
            ],
        ], 'cache');

        self::assertSame('atlas.aael.execution.plan_semantic_proof.v1', $proof['schema_version']);
        self::assertNotContains('score', array_keys($proof));
        self::assertNotContains('aligned', array_keys($proof));
        self::assertNotContains('verdict', array_keys($proof));
        self::assertSame(['Review docs only'], $proof['plan_steps_without_objective_anchor']);
    }

    #[Test]
    public function it_returns_a_byte_identical_empty_plan_no_op_shape(): void
    {
        $prover = new AtlasAaelExecutionPlanSemanticProver;

        $first = $prover->prove(['tasks' => []], 'anchor cache');
        $second = $prover->prove(['tasks' => []], 'anchor cache');

        self::assertSame($first, $second);
        self::assertSame(0.0, $first['objective_token_coverage_ratio']);
        self::assertSame([], $first['plan_steps_without_objective_anchor']);
    }
}
