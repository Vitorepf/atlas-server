<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class AtlasMemoryTemporalDefaultDeriver
{
    public const PROVENANCE = 'default_type_map';
    public const DERIVED_BY = 'MAXH-02';

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function derive(array $attributes): array
    {
        $type = strtolower(trim((string) ($attributes['memory_type'] ?? $attributes['type'] ?? 'technical_context')));
        $recordedAt = $this->carbon($attributes['recorded_at'] ?? null) ?? CarbonImmutable::now();

        $derived = [
            'observed_at' => $recordedAt,
        ];

        $authority = $this->authorityFor($type);
        if ($authority !== null) {
            $derived['authority_level'] = $authority;
        }

        $ttlDays = $this->ttlDaysFor($type);
        if ($ttlDays !== null) {
            $derived['stale_after'] = $recordedAt->addDays($ttlDays);
        }

        return $derived;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function applyMissing(array $payload): array
    {
        $derived = $this->derive($payload);
        $applied = [];

        foreach (['authority_level', 'observed_at', 'stale_after'] as $field) {
            if (($payload[$field] ?? null) === null && array_key_exists($field, $derived)) {
                $payload[$field] = $derived[$field];
                $applied[$field] = $this->serialize($derived[$field]);
            }
        }

        if ($applied !== []) {
            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            data_set($metadata, 'temporal_truth.provenance', data_get($metadata, 'temporal_truth.provenance', self::PROVENANCE));
            data_set($metadata, 'temporal_truth.derived_by', data_get($metadata, 'temporal_truth.derived_by', self::DERIVED_BY));
            data_set($metadata, 'temporal_truth.default_fields', $applied);
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    private function authorityFor(string $type): ?string
    {
        $map = (array) config('atlas.memory_temporal_defaults.authority_by_type', []);
        $value = $map[$type] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function ttlDaysFor(string $type): ?int
    {
        $map = (array) config('atlas.memory_temporal_defaults.ttl_days_by_type', []);
        $value = $map[$type] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        try {
            if ($value instanceof CarbonImmutable) {
                return $value;
            }
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }
            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }
}
