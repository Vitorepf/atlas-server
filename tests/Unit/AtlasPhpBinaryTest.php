<?php

namespace Tests\Unit;

use App\Support\AtlasPhpBinary;
use Tests\TestCase;

class AtlasPhpBinaryTest extends TestCase
{
    public function test_explicit_config_wins_over_candidates(): void
    {
        config([
            'atlas.cli.php_binary' => '/custom/php',
            'atlas.cli.php_binary_candidates' => [PHP_BINARY],
        ]);

        $this->assertSame('/custom/php', AtlasPhpBinary::path());
    }

    public function test_uses_first_executable_candidate_when_not_configured(): void
    {
        config([
            'atlas.cli.php_binary' => null,
            'atlas.cli.php_binary_candidates' => ['/missing/php', PHP_BINARY],
        ]);

        $this->assertSame(PHP_BINARY, AtlasPhpBinary::path());
    }
}
