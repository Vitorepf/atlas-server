<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use Tests\TestCase;

final class AtlasLoopWorkspaceMaterializerSupport2Test extends TestCase
{
    public function test_support_has_distinguishes_matching_path_from_non_matching_entries(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();

        $this->assertTrue($subject->supportHas([
            'composer.json',
            ['content' => '{}'],
            ['path' => './composer.json'],
            ['path' => 'support/other.json'],
        ], 'composer.json'));
        $this->assertFalse($subject->supportHas([
            'composer.json',
            ['content' => '{}'],
            ['path' => 'support/other.json'],
            ['path' => '../nested/config.json'],
        ], 'composer.json'));
    }

    public function test_arm_cross_provider_scope_is_noop_when_disabled(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();
        $explorerTask = [
            'allowed_files' => ['src/Foo.php'],
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
        ];

        $subject->armCrossProviderScope($explorerTask, ['commands' => ['php tests/FooTest.php']], false);

        $this->assertArrayNotHasKey('allowed_globs', $explorerTask['acceptance']);
    }

    public function test_arm_cross_provider_scope_derives_allowed_globs_when_enabled_and_missing(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();
        $explorerTask = [
            'allowed_files' => ['src/Foo.php'],
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
        ];

        $subject->armCrossProviderScope($explorerTask, ['commands' => ['php tests/FooTest.php']], true);

        $this->assertSame(['src/Foo.php'], $explorerTask['acceptance']['allowed_globs']);
    }

    public function test_arm_cross_provider_scope_does_not_create_allowed_globs_when_no_allowed_files_exist(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();
        $explorerTask = [
            'allowed_files' => [],
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
        ];

        $subject->armCrossProviderScope($explorerTask, ['commands' => ['php tests/FooTest.php']], true);

        $this->assertArrayNotHasKey('allowed_globs', $explorerTask['acceptance']);
    }

    public function test_arm_cross_provider_scope_treats_blank_globs_as_missing(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();
        $explorerTask = [
            'allowed_files' => ['src/Foo.php'],
            'acceptance' => [
                'commands' => ['php tests/FooTest.php'],
                'allowed_globs' => ['src/**'],
            ],
        ];
        $acceptance = [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['   ', "\t"],
        ];

        $subject->armCrossProviderScope($explorerTask, $acceptance, true);

        $this->assertSame(['src/Foo.php'], $explorerTask['acceptance']['allowed_globs']);
    }

    public function test_arm_cross_provider_scope_does_not_override_explicit_globs(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();
        $explorerTask = [
            'allowed_files' => ['src/Foo.php'],
            'acceptance' => [
                'commands' => ['php tests/FooTest.php'],
                'allowed_globs' => ['src/**'],
            ],
        ];
        $acceptance = [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['src/**'],
        ];

        $subject->armCrossProviderScope($explorerTask, $acceptance, true);

        $this->assertSame(['src/**'], $explorerTask['acceptance']['allowed_globs']);
    }

    public function test_build_explorer_task_only_sets_provider_when_payload_provider_is_non_blank(): void
    {
        $subject = new AtlasLoopWorkspaceMaterializerSupport2();

        $withProvider = $subject->buildExplorerTask(
            'Improve Foo',
            '/tmp/workspace',
            ['commands' => ['php tests/FooTest.php']],
            [
                'provider' => '  openai  ',
                'allowed_files' => ['src/Foo.php'],
                'validation_commands' => [' php tests/FooTest.php '],
            ],
            'src/Foo.php',
        );

        $withoutProvider = $subject->buildExplorerTask(
            'Improve Foo',
            '/tmp/workspace',
            ['commands' => ['php tests/FooTest.php']],
            [
                'provider' => " \t ",
                'allowed_files' => ['src/Foo.php'],
                'validation_commands' => [' php tests/FooTest.php '],
            ],
            'src/Foo.php',
        );

        $this->assertSame('openai', $withProvider['provider']);
        $this->assertArrayNotHasKey('provider', $withoutProvider);
    }
}
