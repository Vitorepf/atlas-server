<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend;

/**
 * Shared byte-identical helpers de-duplicated across this family (prefix).
 */
trait FrontendPrefixHelper
{
    private function prefix(string $prefix, array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $prefix.'_'.$item)
            ->values()
            ->all();
    }
}
