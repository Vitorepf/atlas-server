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
     * Fail-closed policy check: returns a verdict with named blockers instead of throwing.
     *
     * servable=true  only when schema is active or preview, OR deprecated with a proven
     * lossless upgrade path (successor schema exists and is not itself deprecated/retired).
     * Retired schemas are never servable.
     *
     * Named blockers:
     *   deprecated_schema_no_lossless_upgrade_proof:{schema_id}
     *   retired_schema_not_servable:{schema_id}
     *
     * metadata_missing=true whenever the schema is absent from the registry (empty
     * schema_version or unrecognized id) — servable stays true (an unregistered schema is not
     * deprecated), but this field exists so a missing-metadata packet is never mistaken for a
     * proven-safe one downstream (AC4).
     *
     * blocker_details carries the same information as blockers, but structured per AC2:
     * {blocker, schema_id, schema_status, recommended_migration_target}.
     *
     * Pure — no network, no queue mutation, no provider calls.
     *
     * @param  array<string,mixed>  $packet
     * @return array{servable: bool, blockers: list<string>, schema_status: string, schema_id: string, metadata_missing: bool, recommended_migration_target: ?string, blocker_details: list<array<string,mixed>>}
     */
    public static function check(array $packet): array
    {
        $schemaId = (string) ($packet['schema_version'] ?? '');
        if ($schemaId === '') {
            return self::result(true, [], 'unknown', '', true, null);
        }
        $registry = self::$registryOverride ?? new AtlasMaestroPacketSchemaVersioning();
        if (! $registry->supports($schemaId)) {
            return self::result(true, [], 'unknown', $schemaId, true, null);
        }
        $row = $registry->describe($schemaId);
        $status = (string) ($row['status'] ?? '');
        $successor = (string) ($row['successor'] ?? '');
        $migrationTarget = $successor !== '' ? $successor : null;

        if (! in_array($status, [AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED], true)) {
            return self::result(true, [], $status, $schemaId, false, $migrationTarget);
        }

        $blockers = [];

        if ($status === AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED) {
            $blockers[] = 'retired_schema_not_servable:'.$schemaId;
        } else {
            // deprecated — pass only when a lossless upgrade path is proven
            $hasLosslessUpgrade = $successor !== ''
                && $registry->supports($successor)
                && ! in_array(
                    (string) ($registry->describe($successor)['status'] ?? ''),
                    [AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED],
                    true,
                );
            if (! $hasLosslessUpgrade) {
                $blockers[] = 'deprecated_schema_no_lossless_upgrade_proof:'.$schemaId;
            }
        }

        return self::result($blockers === [], $blockers, $status, $schemaId, false, $migrationTarget);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{servable: bool, blockers: list<string>, schema_status: string, schema_id: string, metadata_missing: bool, recommended_migration_target: ?string, blocker_details: list<array<string,mixed>>}
     */
    private static function result(
        bool $servable,
        array $blockers,
        string $schemaStatus,
        string $schemaId,
        bool $metadataMissing,
        ?string $migrationTarget,
    ): array {
        return [
            'servable' => $servable,
            'blockers' => $blockers,
            'schema_status' => $schemaStatus,
            'schema_id' => $schemaId,
            'metadata_missing' => $metadataMissing,
            'recommended_migration_target' => $migrationTarget,
            'blocker_details' => array_map(static fn (string $blocker): array => [
                'blocker' => $blocker,
                'schema_id' => $schemaId,
                'schema_status' => $schemaStatus,
                'recommended_migration_target' => $migrationTarget,
            ], $blockers),
        ];
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

    /**
     * Check if a deprecated packet field can be safely removed from the schema.
     *
     * Blocks removal when live queued, claimed or blocked packets still reference the
     * deprecated field. Allows removal only when migration evidence and zero live references
     * are present.
     *
     * @param  array<string,mixed>  $proposal  {field_name, schema_version, live_packets: list<array>, migration_evidence: list<string>}
     * @return array{removal_allowed: bool, live_reference_count: int, blocking_fields: list<string>, migration_evidence_required: bool}
     */
    public static function checkRemoval(array $proposal): array
    {
        $fieldName = (string) ($proposal['field_name'] ?? '');
        $livePackets = (array) ($proposal['live_packets'] ?? []);
        $migrationEvidence = (array) ($proposal['migration_evidence'] ?? []);

        // Count live packets that still reference the deprecated field
        $liveReferenceCount = 0;
        $blockingFields = [];
        foreach ($livePackets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $status = (string) ($packet['queue_status'] ?? '');
            // Only count live packets (queued, claimed, blocked)
            if (in_array($status, ['waiting', 'queued', 'enqueued', 'pending', 'claimed', 'blocked', 'dispatched'], true)) {
                // Check if the packet references the deprecated field
                if (array_key_exists($fieldName, $packet) || self::packetReferencesField($packet, $fieldName)) {
                    $liveReferenceCount++;
                    $packetId = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? 'unknown');
                    $blockingFields[] = $packetId;
                }
            }
        }

        // Migration evidence is required when there are live references
        $migrationEvidenceRequired = $liveReferenceCount > 0;

        // Removal is allowed only when zero live references AND migration evidence is present
        $removalAllowed = $liveReferenceCount === 0 && count($migrationEvidence) > 0;

        return [
            'removal_allowed' => $removalAllowed,
            'live_reference_count' => $liveReferenceCount,
            'blocking_fields' => $blockingFields,
            'migration_evidence_required' => $migrationEvidenceRequired,
        ];
    }

    /**
     * Check if a packet references a specific field (directly or in acceptance/objective).
     */
    private static function packetReferencesField(array $packet, string $fieldName): bool
    {
        $haystack = implode(' ', [
            (string) ($packet['objective'] ?? ''),
            implode(' ', (array) ($packet['acceptance_criteria'] ?? [])),
            implode(' ', (array) ($packet['allowed_files'] ?? [])),
            implode(' ', (array) ($packet['required_evidence'] ?? [])),
        ]);

        return str_contains($haystack, $fieldName);
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
