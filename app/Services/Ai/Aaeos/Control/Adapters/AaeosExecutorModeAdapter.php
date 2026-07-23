<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Adapters;

interface AaeosExecutorModeAdapter
{
    public function mode(): string;

    /**
     * @param  array<string,mixed>  $cyclePlan
     * @return array<string,mixed>
     */
    public function accept(array $cyclePlan): array;
}
