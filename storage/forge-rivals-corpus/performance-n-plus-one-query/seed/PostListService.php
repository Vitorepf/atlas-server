<?php

declare(strict_types=1);

namespace App\Services\Posts;

/**
 * Sumário de posts com nome do author.
 *
 * BUG (seed): para cada post no loop, chama `authorById()` → N+1 queries.
 * Com 4 posts isso vira 5 queries (1 + 4). O arm precisa eager-loadar via
 * `authorsByIds()` ANTES do loop e indexar por id, descendo o query count
 * para ≤ 2 — sem mudar o payload JSON.
 *
 * Output esperado: list<array{post_id:int, title:string, author_name:string}>
 */
final class PostListService
{
    public function __construct(
        private readonly FakeQueryRunner $runner,
    ) {}

    /**
     * @return list<array{post_id:int, title:string, author_name:string}>
     */
    public function summarize(): array
    {
        $posts = $this->runner->allPosts();
        $summary = [];
        foreach ($posts as $post) {
            // BUG: 1 query para CADA post. Total = 1 + N.
            $author = $this->runner->authorById($post['author_id']);
            $summary[] = [
                'post_id' => $post['id'],
                'title' => $post['title'],
                'author_name' => $author['name'],
            ];
        }

        return $summary;
    }
}
