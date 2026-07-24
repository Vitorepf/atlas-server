<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\ResolvesGitProjectRoot;
use PHPUnit\Framework\TestCase;

final class ResolvesGitProjectRootTest extends TestCase
{
    public function test_missing_workspace_returns_null(): void
    {
        $host = new class {
            use ResolvesGitProjectRoot;

            public function call(string $ws): ?string
            {
                return $this->projectRootFor($ws);
            }
        };

        $this->assertNull($host->call('/tmp/atlas-no-ws-'.uniqid('', true)));
    }

    public function test_repo_root_resolves_when_inside_git(): void
    {
        $root = realpath(dirname(__DIR__, 5));
        $this->assertNotFalse($root);
        $this->assertDirectoryExists($root.'/.git');

        $host = new class {
            use ResolvesGitProjectRoot;

            public function call(string $ws): ?string
            {
                return $this->projectRootFor($ws);
            }
        };

        $resolved = $host->call($root);
        $this->assertNotNull($resolved);
        $this->assertSame(realpath($root), realpath((string) $resolved));
    }
}
