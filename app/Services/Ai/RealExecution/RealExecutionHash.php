<?php

namespace App\Services\Ai\RealExecution;

use App\Support\CanonicalValue;

class RealExecutionHash
{
    /**
     * @param  mixed  $value
     */
    public static function make($value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        return CanonicalValue::canonicalize($value);
    }
}
