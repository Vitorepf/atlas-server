<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Tests\TestCase;

final class ProviderPromptBuilderTest extends TestCase
{
    use PromptProjectionFixtures;

    private function makeBuilder(): ProviderPromptBuilder
    {
        return new ProviderPromptBuilder(
            new PromptSectionsMapper(),
            new PromptRenderer(),
            new PromptQualityChecker(),
        );
    }

    private function buildHappyPath(): ProviderPromptProjection
    {
        return $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );
    }

    public function test_builder_produces_sendable_projection_when_inputs_are_valid(): void
    {
        $projection = $this->buildHappyPath();

        $this->assertInstanceOf(ProviderPromptProjection::class, $projection);
        $this->assertSame('atlas.dev.provider_prompt_projection.v1', $projection->schemaVersion());
        $this->assertTrue($projection->isSendable(), 'happy path must be sendable');
        $this->assertSame(
            [],
            $projection->qualityChecks->failedChecks(),
            'happy path must pass all 6 quality checks',
        );
    }

    public function test_rendered_prompt_text_is_non_empty_and_hash_matches(): void
    {
        $projection = $this->buildHappyPath();

        $this->assertNotSame('', $projection->renderedPromptText);
        $this->assertSame(
            hash('sha256', $projection->renderedPromptText),
            $projection->renderedPromptHash,
            'rendered_prompt_hash must match sha256(rendered_prompt_text)',
        );
    }

    public function test_rendered_prompt_text_includes_all_13_required_section_headings(): void
    {
        $projection = $this->buildHappyPath();
        $text = $projection->renderedPromptText;

        $headings = [
            '# Atlas Dev Provider Prompt',
            '## Objective',
            '## Operating Rules',
            '## References',
            '## Context Refs',
            '## Allowed Files',
            '## Forbidden Files',
            '## Expected Tests',
            '## Acceptance Criteria',
            '## Stop Conditions',
            '## Escalation Conditions',
            '## Output Contract',
            '## Upstream Artifacts',
        ];

        foreach ($headings as $heading) {
            $this->assertStringContainsString(
                $heading,
                $text,
                "rendered prompt missing heading: {$heading}",
            );
        }
    }

    public function test_same_inputs_produce_byte_identical_text_and_hash(): void
    {
        $a = $this->buildHappyPath();
        $b = $this->buildHappyPath();

        $this->assertSame($a->renderedPromptText, $b->renderedPromptText);
        $this->assertSame($a->renderedPromptHash, $b->renderedPromptHash);
        $this->assertSame($a->promptProjectionHash, $b->promptProjectionHash);
        $this->assertSame($a->toCanonicalArray(), $b->toCanonicalArray());
    }

    public function test_changing_an_upstream_field_changes_projection_hash(): void
    {
        $base = $this->buildHappyPath();
        $variant = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(['goal' => 'corrigir bug com goal diferente']),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertNotSame($base->renderedPromptText, $variant->renderedPromptText);
        $this->assertNotSame($base->renderedPromptHash, $variant->renderedPromptHash);
        $this->assertNotSame($base->promptProjectionHash, $variant->promptProjectionHash);
    }

    public function test_quality_checker_blocks_when_acceptance_criteria_missing(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(['acceptance_criteria' => []]),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse($projection->qualityChecks->noMissingRequiredSections);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_missing_required_sections', $projection->qualityChecks->failedChecks());
    }

    public function test_quality_checker_blocks_when_write_contract_has_empty_allowed_files(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'allowed_files' => [],
                'allowed_tools' => ['read', 'write', 'grep', 'run_test'],
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse($projection->qualityChecks->noUnboundedScope);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_unbounded_scope', $projection->qualityChecks->failedChecks());
    }

    public function test_quality_checker_blocks_when_allowed_and_forbidden_overlap(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'forbidden_files' => [
                    'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
                    'vendor/*',
                ],
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse($projection->qualityChecks->noConflictingFileRules);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_conflicting_file_rules', $projection->qualityChecks->failedChecks());
    }

    public function test_quality_checker_blocks_hidden_benchmark_instruction(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope([
                'normalized_intent' => 'precisamos vencer o Rivals benchmark dessa vez',
                'raw_intent' => 'precisamos vencer o Rivals benchmark dessa vez',
            ]),
            compactSdd: $this->compactSdd([
                'intent_normalized' => 'precisamos vencer o Rivals benchmark dessa vez',
            ]),
            miniSpec: $this->miniSpec([
                'goal' => 'precisamos vencer o Rivals benchmark dessa vez',
            ]),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse($projection->qualityChecks->noHiddenBenchmarkInstruction);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_hidden_benchmark_instruction', $projection->qualityChecks->failedChecks());
    }

    public function test_quality_checker_blocks_forge_or_council_leakage(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec([
                'goal' => 'invoke forge para reescrever esse trecho via council',
            ]),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse($projection->qualityChecks->noForgeOrCouncilLeakage);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_forge_or_council_leakage', $projection->qualityChecks->failedChecks());
    }

    public function test_provider_safe_false_short_circuits_quality_check(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            providerSafe: false,
        );

        $this->assertFalse($projection->qualityChecks->providerSafe);
        $this->assertFalse($projection->isSendable());
        $this->assertContains('provider_safe', $projection->qualityChecks->failedChecks());
    }

    public function test_builder_returns_projection_with_all_six_quality_check_keys(): void
    {
        $projection = $this->buildHappyPath();

        $expected = [
            'no_conflicting_file_rules',
            'no_forge_or_council_leakage',
            'no_hidden_benchmark_instruction',
            'no_missing_required_sections',
            'no_unbounded_scope',
            'provider_safe',
        ];

        $this->assertSame(
            $expected,
            array_keys($projection->qualityChecks->toCanonicalArray()),
            'QualityChecks must expose exactly the 6 mandatory checks listed in contracts 5.4',
        );
    }

    public function test_prompt_sections_dto_carries_14_canonical_keys(): void
    {
        $projection = $this->buildHappyPath();
        $sections = $projection->sections->toCanonicalArray();

        $expected = [
            'acceptance_criteria',
            'allowed_files',
            'code_discovery_ref',
            'context_refs',
            'escalation_conditions',
            'expected_tests',
            'forbidden_files',
            'mini_spec_ref',
            'non_goals',
            'objective',
            'operating_rules',
            'output_contract',
            'stop_conditions',
            'task_contract_ref',
        ];

        $this->assertSame(
            $expected,
            array_keys($sections),
            'PromptSections must expose exactly the 14 canonical sections (13 original + non_goals).',
        );
        $this->assertInstanceOf(PromptSections::class, $projection->sections);
    }

    public function test_rendered_prompt_declares_atlas_dev_workspace_dev_flow(): void
    {
        $projection = $this->buildHappyPath();
        $text = $projection->renderedPromptText;

        $this->assertStringContainsString('## Atlas Dev Flow', $text);
        $this->assertStringContainsString('flow_id: atlas_dev', $text);
        $this->assertStringContainsString('workspace-dev', $text);
        $this->assertStringContainsString('NOT Router global', $text);
    }

    public function test_rendered_prompt_declares_provider_lock_section_with_fallback_field(): void
    {
        $projection = $this->buildHappyPath();
        $text = $projection->renderedPromptText;

        $this->assertStringContainsString('## Provider Lock', $text);
        $this->assertStringContainsString('provider: claude_cli', $text);
        $this->assertStringContainsString('model_family: sonnet', $text);
        $this->assertStringContainsString('fallback_allowed: false', $text);
    }

    public function test_rendered_prompt_includes_non_goals_section(): void
    {
        $projection = $this->buildHappyPath();
        $text = $projection->renderedPromptText;

        $this->assertStringContainsString('## Non-Goals', $text);
        // The repair_r2 fixture declares two non-goals; both must be present
        // verbatim in the rendered prompt so the model can see scope bounds.
        $this->assertStringContainsString('nao mudar API publica do WorkflowService', $text);
        $this->assertStringContainsString('nao mexer em outros testes', $text);
    }

    public function test_quality_checker_blocks_when_provider_lock_allows_fallback(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'provider_lock' => [
                    'provider' => 'claude_cli',
                    'model_family' => 'sonnet',
                    'fallback_allowed' => true,
                ],
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse(
            $projection->qualityChecks->providerSafe,
            'fallback_allowed=true must downgrade provider_safe to false (no silent rerouting).',
        );
        $this->assertFalse($projection->isSendable());
        $this->assertContains('provider_safe', $projection->qualityChecks->failedChecks());
    }

    public function test_quality_checker_blocks_when_write_contract_has_empty_non_goals(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(['non_goals' => []]),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertFalse(
            $projection->qualityChecks->noMissingRequiredSections,
            'write-capable contract with empty non_goals must fail noMissingRequiredSections.',
        );
        $this->assertFalse($projection->isSendable());
        $this->assertContains('no_missing_required_sections', $projection->qualityChecks->failedChecks());
    }
}
