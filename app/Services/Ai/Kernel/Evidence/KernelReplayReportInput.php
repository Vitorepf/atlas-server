<?php

namespace App\Services\Ai\Kernel\Evidence;

final class KernelReplayReportInput
{
    public const DEFAULT_WINDOW_HOURS = 24;

    public const MAX_WINDOW_HOURS = 720;

    public function hours(mixed $value): int
    {
        if (! is_scalar($value)) {
            return self::DEFAULT_WINDOW_HOURS;
        }

        return max(1, min(self::MAX_WINDOW_HOURS, (int) $value));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<int,string>  $allowed
     * @return array<string,string>
     */
    public function scalarFilters(array $input, array $allowed): array
    {
        $filters = [];

        foreach ($allowed as $key) {
            $value = $input[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$key] = trim((string) $value);
            }
        }

        return $filters;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,array<int,string>>  $aliases
     * @return array<string,string>
     */
    public function aliasedScalarFilters(array $input, array $aliases): array
    {
        $filters = [];

        foreach ($aliases as $dimension => $keys) {
            foreach ($keys as $key) {
                $value = $input[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $filters[$dimension] = trim((string) $value);
                    break;
                }
            }
        }

        return $filters;
    }
}
