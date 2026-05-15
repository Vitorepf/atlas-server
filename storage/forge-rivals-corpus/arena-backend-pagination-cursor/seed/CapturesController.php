<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

/**
 * Initial state — offset-based pagination. Arm must replace with cursor.
 */
final class CapturesController
{
    public function index(int $offset = 0, int $limit = 20): array
    {
        return [
            'items' => [],
            'offset' => $offset,
            'limit' => $limit,
        ];
    }
}
