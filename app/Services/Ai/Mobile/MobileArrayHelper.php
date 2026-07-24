<?php

declare(strict_types=1);

namespace App\Services\Ai\Mobile;

/**
 * Shared byte-identical helper de-duplicated across this family (array).
 */
trait MobileArrayHelper
{
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
