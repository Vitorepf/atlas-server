<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionPlanInvariantChecker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasAaelExecutionPlanInvariantCheckerTest extends TestCase
{
    #[Test]
    public function it_reports_missing_acceptance_commands_with_step_evidence(): void
    {
        $checker = new AtlasAaelExecutionPlanInvariantChecker;

        $report = $checker->check([
            'tasks' => [
                [
                    'objective' => 'Step without acceptance commands',
                    'acceptance' => ['commands' => []],
                ],
            ],
        ], ['acceptance_present']);

        self::assertSame([0], $report['steps_missing_acceptance']);
        self::assertStringContainsString('step[0]', $report['invariants_violated'][0]);
        self::assertStringContainsString('acceptance.commands', $report['invariants_violated'][0]);
    }

    #[Test]
    public function it_reports_frozen_scope_touches_with_exact_path_evidence(): void
    {
        $checker = new AtlasAaelExecutionPlanInvariantChecker;

        $report = $checker->check([
            'tasks' => [
                [
                    'objective' => 'Touch frozen scope',
                    'acceptance' => ['commands' => ['vendor/bin/phpunit']],
                    'allowed_files' => [
                        'app/Services/Ai/AutonomousEvolution/Constitution/Frozen/anything.php',
                    ],
                ],
            ],
        ], ['forbidden_files_untouched']);

        self::assertStringContainsString(
            'app/Services/Ai/AutonomousEvolution/Constitution/Frozen/anything.php',
            $report['invariants_violated'][0],
        );
    }

    #[Test]
    public function it_passes_through_an_empty_invariant_list_without_fabrication(): void
    {
        $checker = new AtlasAaelExecutionPlanInvariantChecker;

        $report = $checker->check([
            'tasks' => [
                [
                    'objective' => 'Normal task',
                    'acceptance' => ['commands' => ['vendor/bin/phpunit']],
                ],
            ],
        ], []);

        self::assertSame('atlas.aael.execution.plan_invariant_check.v1', $report['schema_version']);
        self::assertSame([], $report['invariants_preserved']);
        self::assertSame([], $report['invariants_violated']);
    }
}
