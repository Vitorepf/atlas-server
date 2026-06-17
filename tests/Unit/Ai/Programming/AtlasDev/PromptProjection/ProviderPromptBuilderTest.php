<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use PHPUnit\Framework\TestCase;

final class ProviderPromptBuilderTest extends TestCase
{
    use PromptProjectionFixtures;

    private function makeBuilder(): ProviderPromptBuilder
    {
        return new ProviderPromptBuilder(
            new PromptSectionsMapper,
            new PromptRenderer,
            new PromptQualityChecker,
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
            'known_failure_modes',
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
            'PromptSections must expose exactly the 15 canonical sections (13 original + non_goals + known_failure_modes for M5 compounding memory).',
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

    public function test_rendered_prompt_forbids_provider_side_write_tools(): void
    {
        $text = $this->buildHappyPath()->renderedPromptText;

        $this->assertStringContainsString('Nao use ferramentas de escrita, edicao, shell ou teste', $text);
        $this->assertStringContainsString('Atlas Dev aplica o diff e roda verificacao fora do provider', $text);
        $this->assertStringContainsString('nunca aguarde permissao de escrita', $text);
    }

    public function test_rendered_prompt_allows_cursor_workspace_mutation_inside_allowed_files(): void
    {
        $projection = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'provider_lock' => [
                    'provider' => 'cursor_cli',
                    'model_family' => 'composer-2.5-fast',
                    'fallback_allowed' => false,
                ],
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $text = $projection->renderedPromptText;

        $this->assertTrue($projection->isSendable());
        $this->assertStringContainsString('provider: cursor_cli', $text);
        $this->assertStringContainsString('model_family: composer-2.5-fast', $text);
        $this->assertStringContainsString(
            'Cursor CLI deve editar diretamente apenas arquivos listados em allowed_files',
            $text,
        );
        $this->assertStringContainsString(
            'Atlas captura o git diff apos a execucao do Cursor CLI',
            $text,
        );
        $this->assertStringContainsString(
            'workspace_mutation feita diretamente pelo Cursor CLI apenas em allowed_files',
            $text,
        );
        $this->assertStringNotContainsString('Nao use ferramentas de escrita, edicao, shell ou teste', $text);
        $this->assertStringNotContainsString('somente texto de diff; nunca aplique patch diretamente', $text);
        $this->assertStringNotContainsString('nunca aguarde permissao de escrita', $text);
    }

    /**
     * Regression guard for the divergent-pipe bug: codex/minimax/hermes are all
     * registered as workspace-mutating providers in
     * {@see \App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor::providerMutatedWorkspace()}
     * (Atlas reads the post-execution git diff and never applies a returned
     * patch), so the prompt they receive MUST instruct in-place mutation — not
     * the default text-diff contract that previously told them to never edit,
     * which made every real run end in an empty workspaceDiff / no_patch_needed.
     */
    public function test_rendered_prompt_allows_all_mutating_providers_to_edit_workspace(): void
    {
        $cases = [
            ['codex_cli', 'gpt-5.5', 'Codex CLI'],
            ['minimax_m27_cli', 'minimax-m2', 'MiniMax CLI'],
            ['hermes_cli', 'hermes_cli_default', 'Hermes'],
        ];

        foreach ($cases as [$provider, $modelFamily, $label]) {
            $projection = $this->makeBuilder()->build(
                envelope: $this->envelope(),
                compactSdd: $this->compactSdd(),
                miniSpec: $this->miniSpec(),
                taskContract: $this->taskContract([
                    'provider_lock' => [
                        'provider' => $provider,
                        'model_family' => $modelFamily,
                        'fallback_allowed' => false,
                    ],
                ]),
                discovery: $this->codeDiscovery(),
                projection: $this->openBrainProjection(),
            );

            $text = $projection->renderedPromptText;

            $this->assertTrue($projection->isSendable(), $provider.' projection must be sendable');
            $this->assertStringContainsString('provider: '.$provider, $text);
            $this->assertStringContainsString(
                $label.' deve editar diretamente apenas arquivos listados em allowed_files',
                $text,
                $provider.' must receive the in-place mutation contract',
            );
            $this->assertStringContainsString(
                'workspace_mutation feita diretamente pelo '.$label.' apenas em allowed_files',
                $text,
                $provider.' output_contract must declare direct workspace mutation',
            );
            $this->assertStringNotContainsString(
                'somente texto de diff; nunca aplique patch diretamente',
                $text,
                $provider.' must NOT be told to return diff text',
            );
            $this->assertStringNotContainsString(
                'Nao use ferramentas de escrita, edicao, shell ou teste',
                $text,
                $provider.' must NOT be forbidden from write tools',
            );
        }
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

    public function test_rendered_prompt_includes_focused_file_excerpts_for_allowed_files(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-prompt-excerpt-'.bin2hex(random_bytes(6));
        $relativePath = 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php';
        $absolutePath = $workspace.'/'.$relativePath;
        $contents = <<<'PHP'
<?php

final class AtlasCliDevWorkflowService
{
    private const FORBIDDEN_PATHS = ['.env', 'storage/secrets'];

    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP;

        mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, $contents);

        try {
            $projection = $this->makeBuilder()->build(
                envelope: $this->envelope([
                    'workspace' => $workspace,
                ]),
                compactSdd: $this->compactSdd(),
                miniSpec: $this->miniSpec(),
                taskContract: $this->taskContract(),
                discovery: $this->codeDiscovery(),
                projection: $this->openBrainProjection(),
            );

            $text = $projection->renderedPromptText;

            $this->assertStringContainsString('## Focused File Excerpts', $text);
            $this->assertStringContainsString('### '.$relativePath, $text);
            $this->assertStringContainsString('sha256: '.hash('sha256', $contents), $text);
            $this->assertStringContainsString("return 'helo atlas';", $text);
            $this->assertStringContainsString('REDACTED_ENV_FILE', $text);
            $this->assertStringNotContainsString("'.env'", $text);
            $this->assertStringNotContainsString($absolutePath, $text);
            $this->assertTrue($projection->isSendable(), implode(',', $projection->qualityChecks->failedChecks()));
        } finally {
            @unlink($absolutePath);
            @rmdir(dirname($absolutePath));
            @rmdir(dirname(dirname($absolutePath)));
            @rmdir(dirname(dirname(dirname($absolutePath))));
            @rmdir(dirname(dirname(dirname(dirname($absolutePath)))));
            @rmdir($workspace);
        }
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
