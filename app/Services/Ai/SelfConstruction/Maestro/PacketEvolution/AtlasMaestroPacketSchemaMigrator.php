<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\PacketEvolution;

use RuntimeException;

/**
 * Forward-only, deterministic packet-schema migrator.
 *
 * Given a packet tagged with `schema_version` and a chain of pure transforms registered per
 * (from, to) pair, rewrites the packet forward to the target version. Same input ⇒ byte-identical
 * output (timestamps are injected via the Clock contract, so tests can freeze them).
 *
 * Refusals:
 *   - source `schema_version` unknown to versioning ⇒ UnknownSchemaVersionException
 *   - target version status=retired                 ⇒ RuntimeException
 *   - target version lower than source (downgrade)  ⇒ SchemaDowngradeRefusedException
 *
 * Each hop appends a row to the packet's `migration_trail`:
 *   { from, to, transform_id, applied_at }.
 */
final class AtlasMaestroPacketSchemaMigrator
{
    public const SCHEMA = 'atlas.maestro.packet_schema_migrator.v1';

    /** @var array<string, array{transform_id:string, transform: callable}> keyed by "<from>->>><to>" */
    private array $transforms = [];

    /** @var callable(): string */
    private $clock;

    public function __construct(
        private readonly AtlasMaestroPacketSchemaVersioning $versioning,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $transform
     */
    public function registerTransform(string $from, string $to, string $transformId, callable $transform): void
    {
        $this->transforms[$this->key($from, $to)] = ['transform_id' => $transformId, 'transform' => $transform];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function migrateTo(array $packet, string $targetVersion): array
    {
        $source = (string) ($packet['schema_version'] ?? '');
        if (! $this->versioning->supports($source)) {
            throw new UnknownSchemaVersionException('unknown_source_schema_version:'.$source);
        }
        if (! $this->versioning->supports($targetVersion)) {
            throw new UnknownSchemaVersionException('unknown_target_schema_version:'.$targetVersion);
        }
        $sourceRow = $this->versioning->describe($source);
        $targetRow = $this->versioning->describe($targetVersion);

        if ((int) $targetRow['version'] < (int) $sourceRow['version']) {
            throw new SchemaDowngradeRefusedException(sprintf(
                'downgrade_refused: %s (v%d) → %s (v%d) — migrations are forward-only',
                $source,
                (int) $sourceRow['version'],
                $targetVersion,
                (int) $targetRow['version'],
            ));
        }
        if ((string) $targetRow['status'] === AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED) {
            throw new RuntimeException('target_status_retired:'.$targetVersion);
        }

        if ($source === $targetVersion) {
            return $packet; // no-op
        }

        $trail = array_values((array) ($packet['migration_trail'] ?? []));
        $current = $packet;
        $cursor = $source;
        while ($cursor !== $targetVersion) {
            $next = $this->versioning->successorOf($cursor);
            if ($next === null) {
                throw new RuntimeException('no_successor_for:'.$cursor.' (cannot reach '.$targetVersion.')');
            }
            $nextId = (string) $next['id'];
            $key = $this->key($cursor, $nextId);
            if (! isset($this->transforms[$key])) {
                throw new RuntimeException('no_transform_registered:'.$cursor.'->'.$nextId);
            }
            $row = $this->transforms[$key];
            $transformed = ($row['transform'])($current);
            $appliedAt = ($this->clock)();
            $trail[] = [
                'from' => $cursor,
                'to' => $nextId,
                'transform_id' => $row['transform_id'],
                'applied_at' => $appliedAt,
            ];
            $current = is_array($transformed) ? $transformed : [];
            $current['schema_version'] = $nextId;
            $cursor = $nextId;
        }

        $current['migration_trail'] = $trail;

        return $current;
    }

    private function key(string $from, string $to): string
    {
        return $from.'->'.$to;
    }
}

final class UnknownSchemaVersionException extends RuntimeException {}

final class SchemaDowngradeRefusedException extends RuntimeException {}
