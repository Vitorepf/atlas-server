<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusCircuitStateNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): string
    {
        $raw = $payload['circuit_state_in']
            ?? $payload['circuit_state']
            ?? $payload['state']
            ?? '';

        $value = strtolower(trim((string) $raw));

        return match ($value) {
            'half_open', 'half-open', 'probing' => 'half_open',
            'open', 'circuit_open', 'tripped' => 'open',
            'closed', 'ok', 'healthy' => 'closed',
            default => $value === '' ? 'open' : $value,
        };
    }

    public static function nullableFromValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            'half_open', 'half-open', 'probing' => 'half_open',
            'open', 'circuit_open', 'tripped' => 'open',
            'closed', 'ok', 'healthy' => 'closed',
            default => null,
        };
    }
}
