<?php

declare(strict_types=1);

namespace App\Domain\Captures;

/**
 * In-memory query-budget simulator. Calling fetchCaptures() or
 * fetchAuthors() increments queryCount(); the test uses it to assert
 * the eager-load path only issues two queries.
 */
final class CaptureRepository
{
    /** @var list<array{id:string,author_id:string,body:string}> */
    private array $captures = [
        ['id' => 'c1', 'author_id' => 'a1', 'body' => 'one'],
        ['id' => 'c2', 'author_id' => 'a2', 'body' => 'two'],
        ['id' => 'c3', 'author_id' => 'a1', 'body' => 'three'],
        ['id' => 'c4', 'author_id' => 'a3', 'body' => 'four'],
    ];

    /** @var array<string,string> */
    private array $authors = [
        'a1' => 'Alice',
        'a2' => 'Bob',
        'a3' => 'Carol',
    ];

    private int $queryCount = 0;

    /** @return list<array{id:string,author_id:string,body:string}> */
    public function fetchCaptures(int $limit): array
    {
        $this->queryCount++;

        return array_slice($this->captures, 0, $limit);
    }

    public function fetchAuthor(string $authorId): string
    {
        $this->queryCount++;

        return $this->authors[$authorId] ?? '(unknown)';
    }

    /**
     * Batch fetch authors. Use this from the patched listing to keep the
     * query budget at 2.
     *
     * @param  list<string>  $authorIds
     * @return array<string,string>
     */
    public function fetchAuthorsByIds(array $authorIds): array
    {
        $this->queryCount++;
        $out = [];
        foreach ($authorIds as $id) {
            $out[$id] = $this->authors[$id] ?? '(unknown)';
        }

        return $out;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }
}
