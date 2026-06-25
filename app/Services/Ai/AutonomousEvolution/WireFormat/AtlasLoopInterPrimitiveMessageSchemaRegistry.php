<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

use RuntimeException;

/**
 * Typed exception raised by the registry when an unknown schema id is requested.
 */
final class UnknownSchemaException extends RuntimeException
{
}

/**
 * Canonical, versioned registry of cross-primitive message schemas flowing between Atlas Loop,
 * Atlas Cortex and Atlas Maestro.
 *
 * The registry is PURE: no IO, no DB, no provider calls. Schemas are seeded statically so it can
 * be safely consumed by every primitive at boot.
 *
 * INVARIANTS:
 *   - Fail-closed on unknown ids (UnknownSchemaException).
 *   - Each schema declares: required_fields (map: field => {type, nullable}), byte_shape (frozen
 *     ordered descriptor), and family + version.
 */
final class AtlasLoopInterPrimitiveMessageSchemaRegistry
{
    public const TYPE_SCALAR = 'scalar';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';

    /** @var array<string, array<string,mixed>> */
    private const SCHEMAS = [
        'loop.cortex.snapshot.v1' => [
            'id' => 'loop.cortex.snapshot.v1',
            'family' => 'loop.cortex.snapshot',
            'version' => 1,
            'required_fields' => [
                'schema_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'snapshot_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'scope_root' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'built_at_unix' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'inventory' => ['type' => self::TYPE_OBJECT, 'nullable' => false],
                'edges' => ['type' => self::TYPE_ARRAY, 'nullable' => false],
                'orphans' => ['type' => self::TYPE_ARRAY, 'nullable' => false],
            ],
            'byte_shape' => ['schema_id', 'snapshot_id', 'scope_root', 'built_at_unix', 'inventory', 'edges', 'orphans'],
        ],
        'cortex.maestro.fact.v1' => [
            'id' => 'cortex.maestro.fact.v1',
            'family' => 'cortex.maestro.fact',
            'version' => 1,
            'required_fields' => [
                'schema_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'fact_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'source_snapshot_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'observed_at_unix' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'kind' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'payload' => ['type' => self::TYPE_OBJECT, 'nullable' => false],
            ],
            'byte_shape' => ['schema_id', 'fact_id', 'source_snapshot_id', 'observed_at_unix', 'kind', 'payload'],
        ],
        'maestro.loop.outcome.v1' => [
            'id' => 'maestro.loop.outcome.v1',
            'family' => 'maestro.loop.outcome',
            'version' => 1,
            'required_fields' => [
                'schema_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'task_packet_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'outcome' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'verified_chain' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'completed_at_unix' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'evidence_refs' => ['type' => self::TYPE_ARRAY, 'nullable' => false],
                'error' => ['type' => self::TYPE_SCALAR, 'nullable' => true],
            ],
            'byte_shape' => ['schema_id', 'task_packet_id', 'outcome', 'verified_chain', 'completed_at_unix', 'evidence_refs', 'error'],
        ],
        'loop.cortex.scope_comprehension.v1' => [
            'id' => 'loop.cortex.scope_comprehension.v1',
            'family' => 'loop.cortex.scope_comprehension',
            'version' => 1,
            'required_fields' => [
                'schema_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'scope_root' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'comprehension_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'inventory_size' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'orphan_count' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'forbidden_roots' => ['type' => self::TYPE_ARRAY, 'nullable' => false],
            ],
            'byte_shape' => ['schema_id', 'scope_root', 'comprehension_id', 'inventory_size', 'orphan_count', 'forbidden_roots'],
        ],
        'cortex.loop.origination_seed.v1' => [
            'id' => 'cortex.loop.origination_seed.v1',
            'family' => 'cortex.loop.origination_seed',
            'version' => 1,
            'required_fields' => [
                'schema_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'seed_id' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'scope_root' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'rationale' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
                'evidence_refs' => ['type' => self::TYPE_ARRAY, 'nullable' => false],
                'created_at_unix' => ['type' => self::TYPE_SCALAR, 'nullable' => false],
            ],
            'byte_shape' => ['schema_id', 'seed_id', 'scope_root', 'rationale', 'evidence_refs', 'created_at_unix'],
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function get(string $schemaId): array
    {
        if (! array_key_exists($schemaId, self::SCHEMAS)) {
            throw new UnknownSchemaException('unknown_schema_id:'.$schemaId);
        }

        return self::SCHEMAS[$schemaId];
    }

    /**
     * @return list<string> all known schema ids
     */
    public function all(): array
    {
        return array_keys(self::SCHEMAS);
    }

    /**
     * The highest-versioned schema in a family, or null when the family is unknown.
     *
     * @return array<string,mixed>|null
     */
    public function latestFor(string $family): ?array
    {
        $best = null;
        foreach (self::SCHEMAS as $schema) {
            if ((string) $schema['family'] !== $family) {
                continue;
            }
            if ($best === null || (int) $schema['version'] > (int) $best['version']) {
                $best = $schema;
            }
        }

        return $best;
    }

    /**
     * Every version of a given family in ascending version order.
     *
     * @return list<array<string,mixed>>
     */
    public function versionsOf(string $family): array
    {
        $rows = array_values(array_filter(self::SCHEMAS, static fn (array $s): bool => (string) $s['family'] === $family));
        usort($rows, static fn (array $a, array $b): int => (int) $a['version'] <=> (int) $b['version']);

        return $rows;
    }
}
