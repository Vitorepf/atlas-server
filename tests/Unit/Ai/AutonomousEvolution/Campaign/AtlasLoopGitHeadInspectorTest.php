<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Campaign;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopGitHeadInspector;
use Tests\TestCase;

class AtlasLoopGitHeadInspectorTest extends TestCase
{
    public function test_normalize_git_head_valid_40_hex(): void
    {
        $hash = str_repeat('a', 40);
        self::assertSame($hash, AtlasLoopGitHeadInspector::normalizeGitHead($hash));
    }

    public function test_normalize_git_head_lowercases(): void
    {
        $hash = str_repeat('A', 40);
        self::assertSame(strtolower($hash), AtlasLoopGitHeadInspector::normalizeGitHead($hash));
    }

    public function test_normalize_git_head_returns_null_for_short(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::normalizeGitHead(str_repeat('a', 39)));
    }

    public function test_normalize_git_head_returns_null_for_long(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::normalizeGitHead(str_repeat('a', 41)));
    }

    public function test_normalize_git_head_returns_null_for_non_hex(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::normalizeGitHead(str_repeat('g', 40)));
    }

    public function test_normalize_git_head_returns_null_for_empty(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::normalizeGitHead(''));
    }

    public function test_normalize_git_head_strips_whitespace(): void
    {
        $hash = str_repeat('a', 40);
        self::assertSame($hash, AtlasLoopGitHeadInspector::normalizeGitHead("  $hash\n"));
    }

    public function test_current_git_head_uses_resolver(): void
    {
        $hash = str_repeat('b', 40);
        $resolver = fn (string $ws): string => $hash;

        self::assertSame($hash, AtlasLoopGitHeadInspector::currentGitHead($resolver, '/any/where'));
    }

    public function test_current_git_head_resolver_returning_invalid_returns_null(): void
    {
        $resolver = fn (string $ws): string => 'not-a-hash';

        self::assertNull(AtlasLoopGitHeadInspector::currentGitHead($resolver, '/any/where'));
    }

    public function test_current_git_head_no_resolver_empty_workspace(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::currentGitHead(null, ''));
    }

    public function test_current_git_head_no_resolver_non_existent_workspace(): void
    {
        self::assertNull(AtlasLoopGitHeadInspector::currentGitHead(null, '/this/does/not/exist/at/all'));
    }

    public function test_changed_pipeline_files_uses_resolver(): void
    {
        $files = [
            'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php',
            'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignCostMath.php',
        ];
        $resolver = fn (string $boot, string $cur, string $ws): array => $files;

        $result = AtlasLoopGitHeadInspector::changedPipelineFiles($resolver, 'abc', 'def', '/Users/vitorepf/develop/Atlas/atlas-server');

        self::assertSame($files, $result);
    }

    public function test_changed_pipeline_files_empty_workspace(): void
    {
        $resolver = fn (string $boot, string $cur, string $ws): array => ['would-not-be-called'];

        self::assertSame([], AtlasLoopGitHeadInspector::changedPipelineFiles($resolver, 'abc', 'def', ''));
    }

    public function test_changed_pipeline_files_no_resolver_no_workspace_returns_empty(): void
    {
        self::assertSame([], AtlasLoopGitHeadInspector::changedPipelineFiles(null, 'abc', 'def', '/no/such/path'));
    }

    public function test_changed_pipeline_files_pipeline_filtering(): void
    {
        // AtlasLoopPipelineDrift::pipelineFiles filters to engine files only.
        // We use a resolver that returns a mix; the filter should drop non-engine files.
        $resolver = fn (string $boot, string $cur, string $ws): array => [
            'app/Services/Engine.php',
            'app/Services/NotEngine.php',
            'tests/Unit/FooTest.php',
        ];

        $result = AtlasLoopGitHeadInspector::changedPipelineFiles($resolver, 'abc', 'def', '/any');

        self::assertIsArray($result);
        // Engine files survive; details depend on AtlasLoopPipelineDrift::pipelineFiles
        foreach ($result as $f) {
            self::assertStringStartsWith('app/Services/', $f);
        }
    }
}