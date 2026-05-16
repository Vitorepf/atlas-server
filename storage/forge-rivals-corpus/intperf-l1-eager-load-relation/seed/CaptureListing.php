<?php

declare(strict_types=1);

namespace App\Domain\Captures;

final class CaptureListing
{
    public function __construct(private readonly CaptureRepository $repo) {}

    /**
     * BUG: classic 1+N — one query to fetch captures, then one per row
     * for the author. The arm must replace the N author fetches with a
     * single batch fetch (fetchAuthorsByIds) so the total query count
     * is at most 2 regardless of $limit.
     *
     * @return list<array{id:string,body:string,author:string}>
     */
    public function recent(int $limit = 25): array
    {
        $captures = $this->repo->fetchCaptures($limit);
        $out = [];
        foreach ($captures as $row) {
            $out[] = [
                'id' => $row['id'],
                'body' => $row['body'],
                'author' => $this->repo->fetchAuthor($row['author_id']),
            ];
        }

        return $out;
    }
}
