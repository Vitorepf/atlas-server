<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

/**
 * Initial state — priority logic inline. Arm extracts the calc.
 */
final class CapturesController
{
    public function index(array $items): array
    {
        foreach ($items as &$item) {
            $age = time() - (int) ($item['created_at'] ?? 0);
            $tagBoost = isset($item['tags']) && in_array('urgent', (array) $item['tags'], true) ? 100 : 0;
            $item['priority'] = $age + $tagBoost;
        }

        return $items;
    }
}
