<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\PacketEvolution;

use RuntimeException;

/**
 * Typed exception raised when a deprecated/retired packet schema is served without a valid override.
 */
final class DeprecatedSchemaRefusedException extends RuntimeException
{
}

/**
 * Fail-closed gate that refuses to serve, dispatch, or consume any Maestro packet whose
 * schema_version has status='deprecated' or 'retired' in {@see AtlasMaestroPacketSchemaVersioning}.
 *
 * Override path: an operator may pass a scoped, time-boxed token. Tokens are validated against
 * config('atlas.maestro.packet_schema.deprecation_overrides') which holds:
 *   { token_hash, allowed_versions[], expires_at, reason }
 *
 * shouldWarn(): true when the packet's schema is in preview status.
 *
 * Static $registryOverride is a test seam — production reads from a freshly constructed registry.
 */
final class AtlasMaestroPacketSchemaDeprecationGate
{
    public static ?AtlasMaestroPacketSchemaVersioning $registryOverride = null;

    /** @var (callable(): array<string,mixed>)|null */
    public static $overrideConfigResolver = null;

    public static function reset(): void
    {
        self::$registryOverride = null;
        self::$overrideConfigResolver = null;
    }

    /**
     * @param  array<string,mixed>  $packet
     *
     * @throws DeprecatedSchemaRefusedException
     */
    public static function assertServeable(array $packet, ?string $overrideToken = null): void
    {
        $schemaId = (string) ($packet['schema_version'] ?? '');
        if ($schemaId === '') {
            return;
        }
        $registry = self::$registryOverride ?? new AtlasMaestroPacketSchemaVersioning();
        if (! $registry->supports($schemaId)) {
            return;
        }
        $row = $registry->describe($schemaId);
        $status = (string) ($row['status'] ?? '');
        if (! in_array($status, [AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED], true)) {
            return;
        }

        if ($overrideToken !== null && self::overrideAccepts($overrideToken, $schemaId)) {
            return;
        }

        throw new DeprecatedSchemaRefusedException(
            'packet schema is '.$status.': '.$schemaId.($overrideToken !== null ? ' (override token rejected)' : '')
        );
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    public static function shouldWarn(array $packet): bool
    {
        $schemaId = (string) ($packet['schema_version'] ?? '');
        if ($schemaId === '') {
            return false;
        }
        $registry = self::$registryOverride ?? new AtlasMaestroPacketSchemaVersioning();
        if (! $registry->supports($schemaId)) {
            return false;
        }

        return (string) ($registry->describe($schemaId)['status'] ?? '') === AtlasMaestroPacketSchemaVersioning::STATUS_PREVIEW;
    }

    private static function overrideAccepts(string $token, string $schemaId): bool
    {
        $overrides = self::loadOverrides();
        $tokenHash = hash('sha256', $token);
        $now = time();
        foreach ($overrides as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['token_hash'] ?? '') !== $tokenHash) {
                continue;
            }
            $expiresAt = (int) ($row['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < $now) {
                continue;
            }
            $allowed = (array) ($row['allowed_versions'] ?? []);
            if ($allowed === [] || ! in_array($schemaId, $allowed, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadOverrides(): array
    {
        if (is_callable(self::$overrideConfigResolver)) {
            $raw = (self::$overrideConfigResolver)();
            if (is_array($raw)) {
                return array_values($raw);
            }

            return [];
        }
        if (function_exists('config')) {
            $raw = config('atlas.maestro.packet_schema.deprecation_overrides');
            if (is_array($raw)) {
                return array_values($raw);
            }
        }

        return [];
    }
}
