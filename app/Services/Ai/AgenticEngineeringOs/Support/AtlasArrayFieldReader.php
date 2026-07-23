<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasArrayFieldReader
{
    /**
     * @param  array<string,mixed>  $row
     */
    public static function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return AiValueNormalizer::trimmedStringOrNull($value) ?? '';
        }

        return '';
    }
}
