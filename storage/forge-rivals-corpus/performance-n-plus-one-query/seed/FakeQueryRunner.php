<?php

declare(strict_types=1);

namespace App\Services\Posts;

/**
 * DB in-memory para o fixture. Mantém duas "tabelas" (posts, authors) e
 * conta cada chamada como uma query separada — assim o test pode afirmar
 * "no máximo 2 queries" sem depender de framework.
 *
 * O arm pode reescrever PostListService para reduzir as chamadas, mas
 * NÃO pode mexer neste runner (está fora do allowed_files_scope).
 */
final class FakeQueryRunner
{
    public int $queryCount = 0;

    /** @var list<array{id:int, author_id:int, title:string}> */
    private array $posts = [
        ['id' => 1, 'author_id' => 10, 'title' => 'Sonnet'],
        ['id' => 2, 'author_id' => 11, 'title' => 'Opus'],
        ['id' => 3, 'author_id' => 10, 'title' => 'Haiku'],
        ['id' => 4, 'author_id' => 12, 'title' => 'Codex'],
    ];

    /** @var array<int, array{id:int,name:string}> */
    private array $authors = [
        10 => ['id' => 10, 'name' => 'Ada Lovelace'],
        11 => ['id' => 11, 'name' => 'Alan Turing'],
        12 => ['id' => 12, 'name' => 'Grace Hopper'],
    ];

    /**
     * @return list<array{id:int, author_id:int, title:string}>
     */
    public function allPosts(): array
    {
        $this->queryCount++;

        return $this->posts;
    }

    /**
     * @return array{id:int,name:string}
     */
    public function authorById(int $id): array
    {
        $this->queryCount++;
        if (! isset($this->authors[$id])) {
            return ['id' => $id, 'name' => 'unknown'];
        }

        return $this->authors[$id];
    }

    /**
     * Eager load: uma query bulk para todos os authors pedidos.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id:int,name:string}>
     */
    public function authorsByIds(array $ids): array
    {
        $this->queryCount++;
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->authors[$id])) {
                $out[$id] = $this->authors[$id];
            } else {
                $out[$id] = ['id' => $id, 'name' => 'unknown'];
            }
        }

        return $out;
    }
}
