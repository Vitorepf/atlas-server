<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the architectural invariant: the Atlas Dev fast path PromptProjection
 * layer must never depend on the legacy AiPromptBuilder. Bypass is mandatory
 * per atlas-dev contracts doc (no artisanal prompts on the fast path).
 */
final class NoAiPromptBuilderDependencyTest extends TestCase
{
    private const PROMPT_PROJECTION_DIR = __DIR__.'/../../../../../../app/Services/Ai/Programming/AtlasDev/PromptProjection';

    public function test_prompt_projection_classes_do_not_reference_ai_prompt_builder(): void
    {
        $sourceFiles = glob(self::PROMPT_PROJECTION_DIR.'/*.php') ?: [];
        $this->assertNotEmpty($sourceFiles, 'PromptProjection directory must exist with at least one source file.');

        foreach ($sourceFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertNotFalse($contents, "Unable to read {$file}");
            $this->assertStringNotContainsString(
                'AiPromptBuilder',
                $contents,
                basename($file).' must never reference AiPromptBuilder (fast path bypass invariant).',
            );
            $this->assertStringNotContainsString(
                'App\\Services\\Ai\\ValueObjects\\AiPrompt',
                $contents,
                basename($file).' must never depend on legacy AiPrompt classes.',
            );
        }
    }

    public function test_provider_prompt_builder_constructor_depends_only_on_atlas_dev_components(): void
    {
        $reflection = new ReflectionClass(ProviderPromptBuilder::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'ProviderPromptBuilder must declare a constructor');

        $expected = [
            PromptSectionsMapper::class,
            PromptRenderer::class,
            PromptQualityChecker::class,
        ];

        $actual = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $this->assertNotNull($type, 'Each constructor param must declare a type');
            $actual[] = (string) $type;
        }

        $this->assertSame($expected, $actual);
    }

    public function test_provider_prompt_builder_does_not_accept_raw_string_prompt_input(): void
    {
        $reflection = new ReflectionClass(ProviderPromptBuilder::class);
        $build = $reflection->getMethod('build');

        foreach ($build->getParameters() as $param) {
            $type = (string) ($param->getType() ?? '');
            $this->assertNotSame(
                'string',
                $type,
                "ProviderPromptBuilder::build() must not accept a raw string parameter ({$param->getName()}); only typed DTOs are allowed.",
            );
        }

        $returnType = (string) ($build->getReturnType() ?? '');
        $this->assertSame(ProviderPromptProjection::class, $returnType);
    }

    public function test_provider_prompt_builder_has_no_method_named_render_string(): void
    {
        $reflection = new ReflectionClass(ProviderPromptBuilder::class);

        $this->assertFalse($reflection->hasMethod('fromString'));
        $this->assertFalse($reflection->hasMethod('buildFromPrompt'));
        $this->assertFalse($reflection->hasMethod('renderRaw'));
    }
}
