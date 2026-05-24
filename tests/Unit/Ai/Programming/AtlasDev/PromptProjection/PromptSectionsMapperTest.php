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
