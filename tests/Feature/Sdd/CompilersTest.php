<?php

namespace Tests\Feature\Sdd;

use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use Tests\TestCase;

class CompilersTest extends TestCase
{
    public function test_critic_clean_for_well_formed_spec(): void
    {
        $review = (new SpecCritic())->review([
            'spec' => $this->goodSpec(),
        ]);

        $this->assertSame('clean', $review['status']);
        $this->assertFalse($review['has_blocking_questions']);
        $this->assertSame([], $review['blocking_issues']);
    }

    public function test_critic_rejects_short_objective_and_empty_lists(): void
    {
        $review = (new SpecCritic())->review([
            'spec' => [
                'objective' => 'x',
                'context' => 'y',
                'expected_behavior' => 'z',
                'likely_files' => [],
                'risks' => [],
                'tests' => [],
                'evidence_required' => [],
                'rollback' => 'q',
                'completion_criteria' => [],
            ],
        ]);

        $this->assertSame('rejected', $review['status']);
        $this->assertTrue($review['has_blocking_questions']);
        $this->assertNotEmpty($review['clarification_questions']);
    }

    public function test_critic_emits_clarification_questions_per_blocking_issue(): void
    {
        $review = (new SpecCritic())->review([
            'spec' => [
                'objective' => 'algo qualquer',
                'context' => 'short',
                'expected_behavior' => 'short',
                'likely_files' => [],
                'risks' => [],
                'tests' => [],
                'evidence_required' => [],
                'rollback' => 'short',
                'completion_criteria' => [],
            ],
        ]);

        $questions = implode(' || ', $review['clarification_questions']);
        $this->assertStringContainsString('Replace the vague term', $questions);
    }

    public function test_plan_compiler_produces_canonical_fields(): void
    {
        $plan = (new PlanCompiler())->compile($this->goodSpec(), $this->ctxPack());

        foreach ([
            'schema_version', 'target_files', 'forbidden_files',
            'hot_file_ownership', 'technical_approach', 'test_plan',
            'rollback_plan', 'context_digest', 'content_hash',
        ] as $field) {
            $this->assertArrayHasKey($field, $plan, "missing field {$field}");
        }
        $this->assertSame('atlas.sdd_plan.v1', $plan['schema_version']);
        $this->assertSame(64, strlen($plan['content_hash']));
    }

    public function test_plan_compiler_assigns_owner_per_path(): void
    {
        $spec = $this->goodSpec();
        $spec['likely_files'] = [
            'app/Services/Foo.php',
            'app/Console/Commands/BarCommand.php',
            'database/migrations/2026_xyz.php',
            'tests/Feature/FooTest.php',
            'docs/engineering-knowledge-base/foo.md',
        ];
        $plan = (new PlanCompiler())->compile($spec, $this->ctxPack());

        $this->assertSame('service', $plan['hot_file_ownership']['app/Services/Foo.php']);
        $this->assertSame('cli', $plan['hot_file_ownership']['app/Console/Commands/BarCommand.php']);
        $this->assertSame('database', $plan['hot_file_ownership']['database/migrations/2026_xyz.php']);
        $this->assertSame('test', $plan['hot_file_ownership']['tests/Feature/FooTest.php']);
        $this->assertSame('documentation', $plan['hot_file_ownership']['docs/engineering-knowledge-base/foo.md']);
    }

    public function test_task_compiler_generates_one_task_per_owner_with_dependency_chain(): void
    {
        $plan = (new PlanCompiler())->compile([
            'objective' => 'feature x',
            'expected_behavior' => 'works',
            'inputs_outputs' => '...',
            'likely_files' => [
                'app/Services/Foo.php',
                'app/Console/Commands/BarCommand.php',
                'tests/Feature/FooTest.php',
            ],
            'risks' => ['x'],
            'tests' => ['vendor/bin/phpunit'],
            'evidence_required' => ['phpunit_green'],
            'rollback' => 'revert',
        ], $this->ctxPack());

        $tasks = (new TaskCompiler())->compile($plan);

        // 3 owner groups + 1 test task
        $this->assertCount(4, $tasks);
        $this->assertSame('test', $tasks[3]['type']);
        $this->assertNotEmpty($tasks[1]['depends_on']);
    }

    public function test_task_compiler_falls_back_to_single_task_when_no_ownership(): void
    {
        $tasks = (new TaskCompiler())->compile([
            'target_files' => ['app/Foo.php'],
            'forbidden_files' => [],
            'hot_file_ownership' => [],
        ]);

        $this->assertCount(1, $tasks);
        $this->assertSame('T-01-MISC', $tasks[0]['code']);
    }

    /**
     * @return array<string,mixed>
     */
    private function goodSpec(): array
    {
        return [
            'objective' => 'Add a deterministic export endpoint that returns signed PDF bytes.',
            'context' => 'Reports surface needs offline-friendly PDF export tied to the report id.',
            'expected_behavior' => 'GET /reports/{id}/export returns 200 with content-type application/pdf when authorized.',
            'likely_files' => ['app/Services/Reports/Exporter.php', 'tests/Feature/ReportExportTest.php'],
            'inputs_outputs' => 'Inputs: report id. Outputs: signed PDF bytes.',
            'risks' => ['Large reports may exceed memory budget'],
            'tests' => ['vendor/bin/phpunit tests/Feature/ReportExportTest.php'],
            'evidence_required' => ['phpunit_green', 'sample_pdf_artifact_url'],
            'rollback' => 'Revert commit and re-run validation tests on Reports surface.',
            'completion_criteria' => ['PHPUnit suite green', 'CLI gate atlas:reports:smoke passes'],
        ];
    }

    private function ctxPack(): ContextPack
    {
        return new ContextPack(
            stack: 'kernel-programming',
            packages: ['atlas.base.v1'],
            payload: ['envelope' => ['raw_input' => 'x']],
            digest: str_repeat('a', 64),
        );
    }
}
