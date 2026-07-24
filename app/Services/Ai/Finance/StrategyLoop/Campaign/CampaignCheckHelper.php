<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Shared byte-identical helper de-duplicated across this family (check).
 */
trait CampaignCheckHelper
{
    private function check(string $name, bool $passed, string $detail, array $extra = []): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ] + $extra;
    }
}
