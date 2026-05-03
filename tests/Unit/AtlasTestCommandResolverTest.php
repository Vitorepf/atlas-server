<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\AtlasTestCommandResolver;
use Tests\TestCase;

class AtlasTestCommandResolverTest extends TestCase
{
    public function test_prefers_current_php_binary_for_laravel_test_command(): void
    {
        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);

        $command = app(AtlasTestCommandResolver::class)->preferred(['php artisan test']);

        $this->assertSame("'/opt/homebrew/bin/php' -d memory_limit=1024M artisan test", $command);
    }

    public function test_keeps_non_laravel_test_command_unchanged(): void
    {
        $command = app(AtlasTestCommandResolver::class)->preferred(['composer test']);

        $this->assertSame('composer test', $command);
    }
}
