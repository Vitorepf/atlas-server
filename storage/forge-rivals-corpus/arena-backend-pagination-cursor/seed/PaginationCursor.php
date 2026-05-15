<?php

declare(strict_types=1);

namespace App\Services\Captures;

/**
 * Placeholder — arm implements opaque cursor here.
 */
final class PaginationCursor
{
    public static function encode(int $createdAt, string $id): string
    {
        return ''; // to be implemented
    }

    public static function decode(string $cursor): array
    {
        return ['created_at' => 0, 'id' => '']; // to be implemented
    }
}
