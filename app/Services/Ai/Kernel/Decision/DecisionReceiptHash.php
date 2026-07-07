<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\EngineeringKernel\Adapters\ReceiptHashTrait;

final class DecisionReceiptHash
{
    use ReceiptHashTrait;

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        return self::hashPayload($payload);
    }
}
