<?php

namespace App\Services\Ai\Hermes\Mesh;

class SharedHermesCheckpointPolicySeam
{
    public static function boundedString(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
