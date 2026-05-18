<?php

namespace App\Services\Ai\RealExecution;

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
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
