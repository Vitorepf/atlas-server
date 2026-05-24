<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use PHPUnit\Framework\TestCase;

final class PromptRendererTest extends TestCase
{
    use PromptProjectionFixtures;

    private function renderer(): PromptRenderer
    {
        return new PromptRenderer;
    }

    private function sectionsMapper(): PromptSectionsMapper
    {
        return new PromptSectionsMapper;
    }

    public function test_template_resolves_to_atlas_dev_blade_file_on_disk(): void
    {
        $path = $this->renderer()->templatePath();

        $this->assertFileExists($path);
        $this->assertStringEndsWith('atlas_dev/provider_prompt.md.blade.php', $path);
    }

    public function test_renderer_produces_non_empty_markdown_with_all_section_headings(): void
    {
        $sections = $this->sectionsMapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $text = $this->renderer()->render(
            runId: 'run-test',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: [
                'envelope_hash' => 'envelope-hash',
                'mini_spec_hash' => 'mini-spec-hash',
            ],
            sections: $sections,
        );

        $this->assertNotSame('', $text);
        $this->assertStringContainsString('# Atlas Dev Provider Prompt', $text);
        $this->assertStringContainsString('## Output Contract', $text);
        $this->assertStringContainsString('## Upstream Artifacts', $text);
        $this->assertStringContainsString('Run: run-test', $text);
        $this->assertStringContainsString('claude_cli', $text);
    }

    public function test_renderer_is_deterministic_for_same_inputs(): void
    {
        $sections = $this->sectionsMapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $first = $this->renderer()->render(
            runId: 'fixed-run',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: ['envelope_hash' => 'abc'],
            sections: $sections,
        );

        $second = $this->renderer()->render(
            runId: 'fixed-run',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: ['envelope_hash' => 'abc'],
            sections: $sections,
        );

        $this->assertSame($first, $second);
        $this->assertSame(hash('sha256', $first), hash('sha256', $second));
    }

    public function test_renderer_changes_output_when_inputs_change(): void
    {
        $sectionsA = $this->sectionsMapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $sectionsB = $this->sectionsMapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(['goal' => 'OUTRO OBJETIVO COMPLETAMENTE DISTINTO']),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $a = $this->renderer()->render(
            runId: 'r',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: [],
            sections: $sectionsA,
        );

        $b = $this->renderer()->render(
            runId: 'r',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: [],
            sections: $sectionsB,
        );

        $this->assertNotSame($a, $b);
    }

    public function test_template_sha_changes_only_when_template_file_changes(): void
    {
        $first = $this->renderer()->templateSha256();
        $second = (new PromptRenderer)->templateSha256();

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_renderer_normalises_line_endings_to_lf(): void
    {
        $sections = $this->sectionsMapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $text = $this->renderer()->render(
            runId: 'r',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: [],
            sections: $sections,
        );

        $this->assertStringNotContainsString("\r", $text);
    }
}
