<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\AtlasTestCommandResolver;
use Tests\TestCase;

class AtlasTestCommandResolverTest extends TestCase
{
    public function test_prefers_current_php_binary_for_laravel_test_command(): void
    {
        $command = app(AtlasTestCommandResolver::class)->preferred(['php artisan test']);

        $this->assertSame(escapeshellarg(PHP_BINARY).' artisan test', $command);
    }

    public function test_keeps_non_laravel_test_command_unchanged(): void
    {
        $command = app(AtlasTestCommandResolver::class)->preferred(['composer test']);

        $this->assertSame('composer test', $command);
    }
}
