<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiPathMatcher;
use Tests\TestCase;

final class AiPathMatcherTest extends TestCase
{
    public function test_matches_exact_star_glob_and_directory_prefix(): void
    {
        $this->assertTrue(AiPathMatcher::matchesAnyExactStarGlobOrDirectoryPrefix('app/Foo.php', ['app/Foo.php']));
        $this->assertTrue(AiPathMatcher::matchesAnyExactStarGlobOrDirectoryPrefix('app/Http/Controller.php', ['app/*/Controller.php']));
        $this->assertTrue(AiPathMatcher::matchesAnyExactStarGlobOrDirectoryPrefix('routes/api.php', ['routes/']));
    }

    public function test_does_not_treat_question_mark_as_glob_for_this_legacy_contract(): void
    {
        $this->assertFalse(AiPathMatcher::matchesAnyExactStarGlobOrDirectoryPrefix('app/Foo.php', ['app/Fo?.php']));
    }

    public function test_provider_safe_relative_path_rejects_absolute_traversal_empty_null_bytes_and_non_strings(): void
    {
        $this->assertTrue(AiPathMatcher::isProviderSafeRelativePath('app/Foo.php'));
        $this->assertFalse(AiPathMatcher::isProviderSafeRelativePath(''));
        $this->assertFalse(AiPathMatcher::isProviderSafeRelativePath('/app/Foo.php'));
        $this->assertFalse(AiPathMatcher::isProviderSafeRelativePath('app/../Foo.php'));
        $this->assertFalse(AiPathMatcher::isProviderSafeRelativePath("app/Foo.php\0"));
        $this->assertFalse(AiPathMatcher::isProviderSafeRelativePath(123));
    }

    public function test_provider_safe_relative_paths_filters_and_limits_without_trimming(): void
    {
        $this->assertSame(
            [' app/Foo.php ', 'routes/api.php'],
            AiPathMatcher::providerSafeRelativePaths([
                ' app/Foo.php ',
                '',
                '/absolute.php',
                'app/../Foo.php',
                "bad\0path",
                123,
                'routes/api.php',
                'tests/Feature/ExampleTest.php',
            ], 2),
        );
    }

    public function test_provider_safe_relative_paths_preserves_collection_take_negative_limit_semantics(): void
    {
        $this->assertSame(
            ['routes/api.php', 'tests/Feature/ExampleTest.php'],
            AiPathMatcher::providerSafeRelativePaths([
                'app/Foo.php',
                '/absolute.php',
                'routes/api.php',
                'tests/Feature/ExampleTest.php',
            ], -2),
        );
    }
}
