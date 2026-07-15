<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeGraphService;
use PHPUnit\Framework\TestCase;

final class AtlasCodeGraphServiceTest extends TestCase
{
    public function test_parses_topo_order_log_line_with_parents_and_refs(): void
    {
        $service = new AtlasCodeGraphService();

        $node = $service->parseLogLine(
            "9a06fd4c56|4b2b61f974 7c1e8d2a90|Vitor Freire|vitor@example.test|1784316000|HEAD -> main, origin/main",
        );

        self::assertSame('9a06fd4c56', $node['hash']);
        self::assertSame(['4b2b61f974', '7c1e8d2a90'], $node['parents']);
        self::assertSame('Vitor Freire', $node['author_name']);
        self::assertSame('vitor@example.test', $node['author_email']);
        self::assertSame(1784316000, $node['authored_at']);
        self::assertSame(['HEAD -> main', 'origin/main'], $node['refs']);
    }

    public function test_parses_worktree_porcelain_without_exposing_git_metadata(): void
    {
        $service = new AtlasCodeGraphService();

        $worktrees = $service->parseWorktrees(
            "worktree /Users/vitor/worktrees/atlas\nHEAD 9a06fd4c56\nbranch refs/heads/main\n\n".
            "worktree /Users/vitor/worktrees/feature\nHEAD 683af18\nbranch refs/heads/feature\n",
        );

        self::assertSame([
            [
                'path' => '/Users/vitor/worktrees/atlas',
                'branch' => 'main',
                'head' => '9a06fd4c56',
            ],
            [
                'path' => '/Users/vitor/worktrees/feature',
                'branch' => 'feature',
                'head' => '683af18',
            ],
        ], $worktrees);
    }
}
