<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyRoleRedundancyValidator;
use PHPUnit\Framework\TestCase;

final class ForgeTopologyRoleRedundancyValidatorTest extends TestCase
{
    private ForgeTopologyRoleRedundancyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForgeTopologyRoleRedundancyValidator();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->validator->inspect([
            AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
            AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER => ['provider' => 'codex_cli', 'model' => 'gpt-other'],
        ]);

        $this->assertSame('atlas.aaeos.forge_role_redundancy.v1', $result['schema_version']);
        $this->assertArrayHasKey('coherent', $result);
        $this->assertArrayHasKey('defects', $result);
        $this->assertArrayHasKey('primary_provider', $result);
        $this->assertArrayHasKey('reviewer_provider', $result);
        $this->assertArrayHasKey('shares_provider', $result);
    }

    public function testSameProviderAndModelHasNoRealRedundancy(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
            'critical_reviewer' => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertTrue($result['shares_provider']);
        $this->assertSame('claude_cli', $result['primary_provider']);
        $this->assertSame('claude_cli', $result['reviewer_provider']);
        $this->assertSame(
            ['reviewer_equals_builder_provider'],
            array_map(static fn (array $defect): string => $defect['code'], $result['defects']),
        );
    }

    public function testDistinctProvidersAreCoherentWithNoDefects(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
            'critical_reviewer' => ['provider' => 'codex_cli', 'model' => 'gpt-other'],
        ]);

        $this->assertTrue($result['coherent']);
        $this->assertFalse($result['shares_provider']);
        $this->assertSame([], $result['defects']);
        $this->assertSame('claude_cli', $result['primary_provider']);
        $this->assertSame('codex_cli', $result['reviewer_provider']);
    }

    public function testMissingCriticalReviewerEmitsDefectAndNullReviewerProvider(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertNull($result['reviewer_provider']);
        $this->assertSame('claude_cli', $result['primary_provider']);
        $this->assertFalse($result['shares_provider']);
        $this->assertSame(
            ['critical_reviewer_missing'],
            array_map(static fn (array $defect): string => $defect['code'], $result['defects']),
        );
    }

    public function testMissingPrimaryBuilderEmitsDefectWithoutReviewerEqualsDefect(): void
    {
        $result = $this->validator->inspect([
            'critical_reviewer' => ['provider' => 'codex_cli', 'model' => 'gpt-other'],
        ]);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);

        $this->assertFalse($result['coherent']);
        $this->assertNull($result['primary_provider']);
        $this->assertSame('codex_cli', $result['reviewer_provider']);
        $this->assertContains('primary_builder_missing', $codes);
        $this->assertNotContains('reviewer_equals_builder_provider', $codes);
    }

    public function testSameProviderDifferentModelsDisambiguate(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => 'claude_cli', 'model' => 'claude-opus-4-7'],
            'critical_reviewer' => ['provider' => 'claude_cli', 'model' => 'claude-sonnet-4-5'],
        ]);

        $this->assertFalse($result['shares_provider']);
        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertSame('claude_cli', $result['primary_provider']);
        $this->assertSame('claude_cli', $result['reviewer_provider']);
    }

    public function testIdentityComparisonIsCaseInsensitiveAndTrimmed(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => '  Claude_CLI ', 'model' => ' Claude-Opus-4-7'],
            'critical_reviewer' => ['provider' => 'CLAUDE_CLI', 'model' => 'claude-opus-4-7  '],
        ]);

        $this->assertTrue($result['shares_provider']);
        $this->assertFalse($result['coherent']);
        $this->assertSame('claude_cli', $result['primary_provider']);
        $this->assertSame('claude_cli', $result['reviewer_provider']);
        $this->assertSame(
            ['reviewer_equals_builder_provider'],
            array_map(static fn (array $defect): string => $defect['code'], $result['defects']),
        );
    }

    public function testGeneralizesToProvidersOutsideCanonicalExamples(): void
    {
        $result = $this->validator->inspect([
            'primary_builder' => ['provider' => 'minimax_self_host', 'model' => 'minimax-m3'],
            'critical_reviewer' => ['provider' => 'minimax_self_host', 'model' => 'minimax-m3'],
        ]);

        $this->assertTrue($result['shares_provider']);
        $this->assertFalse($result['coherent']);
        $this->assertSame('minimax_self_host', $result['primary_provider']);
        $this->assertSame('minimax_self_host', $result['reviewer_provider']);
        $this->assertSame(
            ['reviewer_equals_builder_provider'],
            array_map(static fn (array $defect): string => $defect['code'], $result['defects']),
        );
    }

    public function testBothRolesMissingEmitsBothMissingDefectsAndNullProviders(): void
    {
        $result = $this->validator->inspect([]);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);

        $this->assertFalse($result['coherent']);
        $this->assertNull($result['primary_provider']);
        $this->assertNull($result['reviewer_provider']);
        $this->assertFalse($result['shares_provider']);
        $this->assertSame(['primary_builder_missing', 'critical_reviewer_missing'], $codes);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $roles = [
            'primary_builder' => ['provider' => 'gemini_cli', 'model' => 'gemini-3-pro'],
            'critical_reviewer' => ['provider' => 'cursor_cli', 'model' => 'composer-2-5'],
        ];

        $this->assertSame(
            $this->validator->inspect($roles),
            $this->validator->inspect($roles),
        );
    }
}
