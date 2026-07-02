<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use PHPUnit\Framework\TestCase;

final class PromptSectionsMapperTest extends TestCase
{
    use PromptProjectionFixtures;

    private function mapper(): PromptSectionsMapper
    {
        return new PromptSectionsMapper;
    }

    public function test_mapper_pulls_objective_from_mini_spec_goal(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertStringContainsString('AtlasCliDevWorkflowServiceTest', $sections->objective);
    }

    public function test_mapper_uses_envelope_normalized_intent_when_goal_is_empty(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(['goal' => '   ']),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertSame(
            'corrigir teste falhando em AtlasCliDevWorkflowServiceTest',
            $sections->objective,
        );
    }

    public function test_mapper_emits_canonical_atlas_dev_operating_rules(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertSame(
            PromptSectionsMapper::ATLAS_DEV_OPERATING_RULES,
            $sections->operatingRules,
            'operating_rules must be the frozen canon list — never user-derived.',
        );
    }

    public function test_mapper_emits_output_contract_clauses_with_no_patch_and_blocked_escape_hatches(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $joined = implode("\n", $sections->outputContract);
        $this->assertStringContainsString('diff em formato unified', $joined);
        $this->assertStringContainsString('changed_files', $joined);
        $this->assertStringContainsString('no_patch_needed', $joined);
        $this->assertStringContainsString('blocked', $joined);
        $this->assertStringContainsString('acceptance_criteria', $joined);
    }

    public function test_mapper_surfaces_discovery_callers_and_tests_as_readable_context_refs(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery([
                'likely_callers' => [
                    ['kind' => 'code', 'ref' => 'app/Services/Bar/BarService.php:42', 'reason' => 'calls FooService::value()'],
                ],
                'related_tests' => [
                    ['kind' => 'test', 'ref' => 'tests/Unit/Services/Bar/BarServiceTest.php', 'reason' => 'pins BarService behavior'],
                ],
            ]),
            projection: $this->openBrainProjection(),
        );

        $this->assertContains(
            'code://app/Services/Bar/BarService.php:42 :: calls FooService::value()',
            $sections->contextRefs,
            'discovery likely-callers must ride into the prompt as readable refs (the E5 gate acts on them later)',
        );
        $this->assertContains(
            'test://tests/Unit/Services/Bar/BarServiceTest.php :: pins BarService behavior',
            $sections->contextRefs,
            'discovery related-tests must ride into the prompt as readable refs',
        );
    }

    public function test_mapper_caps_discovery_refs_per_group(): void
    {
        $callers = [];
        for ($i = 0; $i < 20; $i++) {
            $callers[] = ['kind' => 'code', 'ref' => "app/C{$i}.php", 'reason' => "caller {$i}"];
        }

        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(['likely_callers' => $callers]),
            projection: $this->openBrainProjection(),
        );

        $callerRefs = array_values(array_filter(
            $sections->contextRefs,
            static fn (string $r): bool => str_starts_with($r, 'code://app/C'),
        ));

        $this->assertCount(8, $callerRefs, 'discovery refs are capped at 8 per group so evidence never floods the prompt');
        $this->assertSame('code://app/C0.php :: caller 0', $callerRefs[0], 'cap keeps manifest order (deterministic)');
    }

    public function test_mapper_surfaces_proven_exemplars_with_readable_objective(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            provenExemplars: [
                ['run_id' => 'run-good', 'objective_excerpt' => 'Adicionar cache ao FooService', 'verification_command' => 'php artisan test --filter=FooServiceTest'],
                // No readable objective => teaches nothing => skipped.
                ['run_id' => 'run-opaque', 'objective_excerpt' => '', 'verification_command' => 'x'],
            ],
        );

        $this->assertContains(
            'exemplar://run-good :: did "Adicionar cache ao FooService" — verified via php artisan test --filter=FooServiceTest',
            $sections->contextRefs,
        );
        $joined = implode("\n", $sections->contextRefs);
        $this->assertStringNotContainsString('run-opaque', $joined, 'exemplar without objective must be skipped');
    }

    public function test_mapper_dedupes_context_refs(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertSame(array_unique($sections->contextRefs), $sections->contextRefs);
    }

    public function test_mapper_propagates_allowed_and_forbidden_files_from_task_contract(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertSame(
            ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            $sections->allowedFiles,
        );
        $this->assertContains('vendor/*', $sections->forbiddenFiles);
        $this->assertContains('node_modules/*', $sections->forbiddenFiles);
    }

    public function test_mapper_builds_refs_using_atlas_dev_uri_scheme(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertStringStartsWith('atlas-dev://mini-spec/', $sections->miniSpecRef);
        $this->assertStringStartsWith('atlas-dev://task-contract/', $sections->taskContractRef);
        $this->assertStringStartsWith('atlas-dev://code-discovery/', $sections->codeDiscoveryRef);
    }

    public function test_mapper_marks_sections_as_provider_safe(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertTrue($sections->isProviderSafe());
    }

    public function test_mapper_propagates_non_goals_from_mini_spec(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        // The repair_r2 fixture ships two scope-bounding clauses.
        $this->assertSame(
            [
                'nao mudar API publica do WorkflowService',
                'nao mexer em outros testes',
            ],
            $sections->nonGoals,
        );
    }

    public function test_mapper_emits_empty_non_goals_when_mini_spec_has_none(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(['non_goals' => []]),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertSame([], $sections->nonGoals);
    }
}
