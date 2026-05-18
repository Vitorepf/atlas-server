<?php

namespace App\Services\Ai\AutonomousEngineering;

class AutonomousEngineeringHash
{
    public static function make(mixed $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item);
        }

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
