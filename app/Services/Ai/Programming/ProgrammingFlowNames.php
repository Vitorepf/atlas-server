<?php

namespace App\Services\Ai\Programming;

final class ProgrammingFlowNames
{
    public static function canonical(string $flow): string
    {
        return str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow;
    }
}
