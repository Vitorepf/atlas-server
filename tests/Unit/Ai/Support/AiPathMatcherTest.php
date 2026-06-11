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
}
