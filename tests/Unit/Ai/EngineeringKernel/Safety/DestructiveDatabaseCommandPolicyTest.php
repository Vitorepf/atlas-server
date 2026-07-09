<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Safety;

use App\Services\Ai\EngineeringKernel\Safety\DestructiveDatabaseCommandPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DestructiveDatabaseCommandPolicyTest extends TestCase
{
    #[DataProvider('destructiveCommands')]
    public function test_refuses_destructive_database_commands(string $command): void
    {
        $this->assertNotNull(
            DestructiveDatabaseCommandPolicy::reasonIfDestructive($command),
            $command.' must be refused',
        );
        $this->assertTrue(DestructiveDatabaseCommandPolicy::isDestructive($command));
    }

    #[DataProvider('safeCommands')]
    public function test_allows_non_destructive_commands(string $command): void
    {
        $this->assertNull(DestructiveDatabaseCommandPolicy::reasonIfDestructive($command));
        $this->assertFalse(DestructiveDatabaseCommandPolicy::isDestructive($command));
    }

    /** @return array<string, array{0:string}> */
    public static function destructiveCommands(): array
    {
        return [
            'fresh' => ['php artisan migrate:fresh'],
            'fresh_force' => ['php artisan migrate:fresh --force'],
            'refresh' => ['php artisan migrate:refresh'],
            'reset' => ['php artisan migrate:reset'],
            'wipe' => ['php artisan db:wipe'],
            'wipe_force' => ['/opt/homebrew/bin/php artisan db:wipe --force'],
            'fresh_seed' => ['php artisan migrate:fresh --seed'],
        ];
    }

    /** @return array<string, array{0:string}> */
    public static function safeCommands(): array
    {
        return [
            'migrate' => ['php artisan migrate'],
            'migrate_status' => ['php artisan migrate:status'],
            'test' => ['php artisan test tests/Unit/ExampleTest.php'],
            'docs_health' => ['php artisan atlas:engineering:knowledge docs-health --json'],
        ];
    }
}
