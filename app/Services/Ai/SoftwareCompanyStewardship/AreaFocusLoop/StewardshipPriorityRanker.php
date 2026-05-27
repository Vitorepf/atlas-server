<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

interface StewardshipPriorityRanker
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rank(array $input): array;
}
