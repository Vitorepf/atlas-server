<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

/**
 * Derives conflict-path and target-path from a supply spec array.
 *
 * Extracted from AtlasLoopQueueRefiller to reduce the god-class.
 * Pure static methods — no instance state.
 */
final class AtlasLoopRefillerSupplySpecPathResolver
{
    /**
     * @param  array<string,mixed>  $spec
     */
    public static function conflictPath(array $spec): string
    {
        $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));
        $first = (string) ($members[0] ?? '');

        return $first !== '' ? $first : self::targetPath($spec);
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    public static function targetPath(array $spec): string
    {
        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $expectedPath = ltrim(trim((string) ($payload['expected_path'] ?? '')), '/');
        if ($expectedPath !== '') {
            return $expectedPath;
        }

        $capability = trim((string) ($payload['capability'] ?? ''));
        if ($capability !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $capability) === 1) {
            return 'app/Services/Ai/AutonomousEvolution/'.$capability.'.php';
        }

        $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));

        return ltrim((string) ($members[0] ?? ''), '/');
    }
}
