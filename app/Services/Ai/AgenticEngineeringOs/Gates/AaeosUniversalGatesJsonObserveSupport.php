<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Gates;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Shared JSON path loading + observe merge for atlas:aeos:observe universal-gates (full-pass peel).
 */
final class AaeosUniversalGatesJsonObserveSupport
{
    /**
     * @return array<string, mixed>|null
     */
    public static function loadJsonFile(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public static function loadSignalsFromPath(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return AiValueNormalizer::arrayOrEmpty(self::loadJsonFile($path));
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  callable(array<string, mixed>): mixed  $projector
     * @return array{0: array<string, mixed>, 1: string|null} report + failure reason
     */
    public static function appendOptionalJsonObserve(
        array $report,
        string $path,
        string $observeKey,
        callable $projector,
        string $optionLabel,
    ): array {
        if ($path === '') {
            return [$report, null];
        }
        $payload = self::loadJsonFile($path);
        if ($payload === null) {
            return [$report, 'universal-gates --'.$optionLabel.' must be a readable JSON object'];
        }
        $report['observe'] = array_merge(
            AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
            [$observeKey => $projector($payload)],
        );

        return [$report, null];
    }
}
