<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerSupplySpecPathResolver;
use Tests\TestCase;

class AtlasLoopRefillerSupplySpecPathResolverTest extends TestCase
{
    public function test_target_path_uses_expected_path_from_payload(): void
    {
        $spec = ['payload' => ['expected_path' => 'app/Services/Foo.php']];

        self::assertSame('app/Services/Foo.php', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_strips_leading_slash_from_expected_path(): void
    {
        $spec = ['payload' => ['expected_path' => '/app/Services/Foo.php']];

        self::assertSame('app/Services/Foo.php', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_uses_capability_when_no_expected_path(): void
    {
        $spec = ['payload' => ['capability' => 'AtlasLoopBrainService']];

        self::assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopBrainService.php', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_ignores_invalid_capability(): void
    {
        $spec = ['payload' => ['capability' => '123-invalid']];

        // Falls through to members lookup
        self::assertSame('', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_falls_back_to_first_member(): void
    {
        $spec = ['members' => ['app/Foo.php', 'app/Bar.php']];

        self::assertSame('app/Foo.php', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_falls_back_to_first_member_with_leading_slash(): void
    {
        $spec = ['members' => ['/app/Baz.php']];

        self::assertSame('app/Baz.php', AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec));
    }

    public function test_target_path_returns_empty_for_empty_spec(): void
    {
        self::assertSame('', AtlasLoopRefillerSupplySpecPathResolver::targetPath([]));
    }

    public function test_conflict_path_uses_first_string_member(): void
    {
        $spec = ['members' => ['app/Conflict.php', 'app/Other.php']];

        self::assertSame('app/Conflict.php', AtlasLoopRefillerSupplySpecPathResolver::conflictPath($spec));
    }

    public function test_conflict_path_falls_back_to_target_path_when_no_members(): void
    {
        $spec = ['payload' => ['expected_path' => 'app/Fallback.php']];

        self::assertSame('app/Fallback.php', AtlasLoopRefillerSupplySpecPathResolver::conflictPath($spec));
    }

    public function test_conflict_path_ignores_non_string_members(): void
    {
        $spec = ['members' => [42, null, 'app/Real.php']];

        self::assertSame('app/Real.php', AtlasLoopRefillerSupplySpecPathResolver::conflictPath($spec));
    }

    public function test_conflict_path_returns_empty_for_empty_spec(): void
    {
        self::assertSame('', AtlasLoopRefillerSupplySpecPathResolver::conflictPath([]));
    }

    public function test_both_methods_are_deterministic(): void
    {
        $spec = ['payload' => ['expected_path' => 'app/Test.php'], 'members' => ['app/M.php']];

        self::assertSame(
            AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec),
            AtlasLoopRefillerSupplySpecPathResolver::targetPath($spec),
        );
        self::assertSame(
            AtlasLoopRefillerSupplySpecPathResolver::conflictPath($spec),
            AtlasLoopRefillerSupplySpecPathResolver::conflictPath($spec),
        );
    }
}
