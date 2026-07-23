<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

interface AaeosModeLiveDispatcher
{
    public function mode(): string;

    /**
     * @param  array<string,mixed>  $cyclePlan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function liveDispatch(array $cyclePlan, array $options = []): array;
}
