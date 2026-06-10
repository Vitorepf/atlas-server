<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusLoopPayloadNormalizer
{
    /**
     * A wiring-phase `fixture` may carry the whole input bundle. Fold it under
     * the explicit input so direct keys keep precedence.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function withoutVolatileReportFields(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public static function withoutVolatileEventFields(array $event): array
    {
        unset($event['recorded_at'], $event['event_hash']);

        return $event;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    public static function withoutFields(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function payloadArrayCount(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? count($value) : 0;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function payloadHasNonEmptyArray(array $payload, string $key): bool
    {
        return self::payloadArrayCount($payload, $key) > 0;
    }

    public static function mergeTarget(mixed $value, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));
        $fallback = strtolower(trim($fallback));
        if (! in_array($fallback, ['integration_lane', 'main', 'none'], true)) {
            $fallback = 'none';
        }

        return in_array($normalized, ['integration_lane', 'main', 'none'], true) ? $normalized : $fallback;
    }

    public static function loopSource(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match (true) {
            $normalized === 'canonical_backlog' => 'canonical_backlog',
            str_contains($normalized, 'self_construction') => 'self_construction_packet',
            $normalized === 'scanner' => 'scanner',
            $normalized === '' => 'canonical_backlog',
            default => $normalized,
        };
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function repoRoot(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }
        if ($candidate === '') {
            $candidate = getcwd() ?: '';
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function repoRootOrEmpty(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function repoRootRaw(array $input): string
    {
        $root = trim((string) ($input['repo_root'] ?? ''));
        if ($root !== '') {
            return $root;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '.');
    }
}
