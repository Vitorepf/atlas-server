<?php

declare(strict_types=1);

namespace Tests\Unit\Posts;

use App\Services\Posts\FakeQueryRunner;
use App\Services\Posts\PostListService;
use PHPUnit\Framework\TestCase;

final class PostListServiceTest extends TestCase
{
    public function test_summary_returns_expected_payload(): void
    {
        $svc = new PostListService(new FakeQueryRunner);
        $summary = $svc->summarize();

        $this->assertSame([
            ['post_id' => 1, 'title' => 'Sonnet', 'author_name' => 'Ada Lovelace'],
            ['post_id' => 2, 'title' => 'Opus', 'author_name' => 'Alan Turing'],
            ['post_id' => 3, 'title' => 'Haiku', 'author_name' => 'Ada Lovelace'],
            ['post_id' => 4, 'title' => 'Codex', 'author_name' => 'Grace Hopper'],
        ], $summary, 'payload precisa ficar idêntico ao baseline');
    }

    public function test_summary_runs_in_at_most_two_queries(): void
    {
        $runner = new FakeQueryRunner;
        $svc = new PostListService($runner);
        $svc->summarize();

        $this->assertLessThanOrEqual(
            2,
            $runner->queryCount,
            "esperado ≤ 2 queries (1 para posts + 1 eager load de authors); obtido {$runner->queryCount}",
        );
    }
}
