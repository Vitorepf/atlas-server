<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AtlasUnifiedRealityGraphService
{
    public const SNAPSHOT_SCHEMA = 'atlas.aucri.reality_snapshot.v1';

    public const ENTITY_SCHEMA = 'atlas.aucri.reality_entity.v1';

    public const EDGE_SCHEMA = 'atlas.aucri.reality_edge.v1';

    public const SOURCE_SCHEMA = 'atlas.aucri.reality_source.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $risk = $this->risk($input['risk'] ?? $input['risk_level'] ?? 'low');
        $hours = max(1, min(8760, (int) ($input['hours'] ?? 720)));
        $limit = max(1, min(250, (int) ($input['limit'] ?? 100)));
        $since = CarbonImmutable::now()->subHours($hours);

        if (! Schema::hasTable('atlas_reality_entities') || ! Schema::hasTable('atlas_reality_relationships')) {
            return $this->missingTablesPayload($risk, $hours, $limit);
        }

        $entities = AtlasRealityEntity::query()
            ->where('created_at', '>=', $since)
            ->orWhere('updated_at', '>=', $since)
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        $relationships = AtlasRealityRelationship::query()
            ->where('created_at', '>=', $since)
            ->orWhere('updated_at', '>=', $since)
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        return $this->buildPayload($entities, $relationships, $risk, $hours, $limit);
    }

    /**
     * @param  Collection<int,AtlasRealityEntity>  $entities
     * @param  Collection<int,AtlasRealityRelationship>  $relationships
     * @return array<string,mixed>
     */
    private function buildPayload(Collection $entities, Collection $relationships, string $risk, int $hours, int $limit): array
    {
        $entityProjections = $entities
            ->map(fn (AtlasRealityEntity $entity): array => $this->entityProjection($entity))
            ->values();

        $entityById = $entities->keyBy(fn (AtlasRealityEntity $entity): string => (string) $entity->id);
        $edgeProjections = $relationships
            ->map(fn (AtlasRealityRelationship $relationship): array => $this->edgeProjection($relationship, $entityById))
            ->values();

        $sources = $this->sources($entityProjections, $edgeProjections);
        $quality = $this->quality($entityProjections, $edgeProjections);
        $status = $this->status($quality, $risk, $entityProjections->count());

        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'window' => [
                'hours' => $hours,
                'limit' => $limit,
            ],
            'summary' => [
                'entities_total' => $entityProjections->count(),
                'edges_total' => $edgeProjections->count(),
                'sources_total' => count($sources),
                'stale_entities' => $quality['stale_entities'],
                'weak_entities' => $quality['weak_entities'],
                'unsourced_entities' => $quality['unsourced_entities'],
                'unsourced_edges' => $quality['unsourced_edges'],
            ],
            'entities' => $entityProjections->all(),
            'edges' => $edgeProjections->all(),
            'sources' => $sources,
            'quality_gate' => [
                'status' => $quality['status'],
                'risk_level' => $risk,
                'blockers' => $quality['blockers'],
                'warnings' => $quality['warnings'],
                'freshness_policy' => 'stale_or_unknown_entities_warn; high_risk_unsourced_blocks',
                'authority_policy' => 'operator_declared_allowed; inferred_requires_review',
            ],
            'claim_policy' => [
                'truth_graph_complete' => false,
                'recommendation_only' => true,
                'providers_invoked' => false,
                'writes' => false,
                'benchmark_run' => false,
                'external_execution_performed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function entityProjection(AtlasRealityEntity $entity): array
    {
        $evidenceRefs = $this->stringList($entity->evidence_refs);
        $sourceRefs = $this->stringList($entity->source_refs);
        $freshness = $this->freshness($entity);
        $authority = (string) ($entity->authority_level ?: 'unknown');

        return [
            'schema_version' => self::ENTITY_SCHEMA,
            'entity_ref' => MissionCanonicalHash::sha256((string) $entity->id),
            'entity_key_hash' => MissionCanonicalHash::sha256((string) $entity->entity_key),
            'entity_type' => (string) $entity->entity_type,
            'name_hash' => MissionCanonicalHash::sha256((string) $entity->name),
            'label' => Str::limit((string) $entity->name, 96),
            'status' => (string) $entity->status,
            'authority_level' => $authority,
            'freshness_status' => $freshness['status'],
            'confidence' => $this->confidence($authority, $freshness['status'], count($evidenceRefs), count($sourceRefs)),
            'evidence_ref_hashes' => array_map(static fn (string $ref): string => MissionCanonicalHash::sha256($ref), $evidenceRefs),
            'source_ref_hashes' => array_map(static fn (string $ref): string => MissionCanonicalHash::sha256($ref), $sourceRefs),
            'observed_at' => $entity->observed_at?->toJSON(),
            'valid_until' => $entity->valid_until?->toJSON(),
            'entity_hash' => (string) $entity->entity_hash,
        ];
    }

    /**
     * @param  Collection<string,AtlasRealityEntity>  $entityById
     * @return array<string,mixed>
     */
    private function edgeProjection(AtlasRealityRelationship $relationship, Collection $entityById): array
    {
        $evidenceRefs = $this->stringList($relationship->evidence_refs);
        $sourceEntity = $entityById->get((string) $relationship->source_entity_id);
        $targetEntity = $entityById->get((string) $relationship->target_entity_id);

        return [
            'schema_version' => self::EDGE_SCHEMA,
            'edge_ref' => MissionCanonicalHash::sha256((string) $relationship->id),
            'source_entity_ref' => MissionCanonicalHash::sha256((string) $relationship->source_entity_id),
            'target_entity_ref' => MissionCanonicalHash::sha256((string) $relationship->target_entity_id),
            'source_entity_type' => $sourceEntity?->entity_type,
            'target_entity_type' => $targetEntity?->entity_type,
            'relationship_type' => (string) $relationship->relationship_type,
            'status' => (string) $relationship->status,
            'weight' => round((float) $relationship->weight, 2),
            'confidence' => count($evidenceRefs) > 0 ? 0.82 : 0.42,
            'evidence_ref_hashes' => array_map(static fn (string $ref): string => MissionCanonicalHash::sha256($ref), $evidenceRefs),
            'relationship_hash' => (string) $relationship->relationship_hash,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $entities
     * @param  Collection<int,array<string,mixed>>  $edges
     * @return array<int,array<string,mixed>>
     */
    private function sources(Collection $entities, Collection $edges): array
    {
        $hashes = [];
        foreach ($entities as $entity) {
            foreach ((array) ($entity['evidence_ref_hashes'] ?? []) as $hash) {
                $hashes[] = ['kind' => 'entity_evidence', 'hash' => $hash];
            }
            foreach ((array) ($entity['source_ref_hashes'] ?? []) as $hash) {
                $hashes[] = ['kind' => 'entity_source', 'hash' => $hash];
            }
        }
        foreach ($edges as $edge) {
            foreach ((array) ($edge['evidence_ref_hashes'] ?? []) as $hash) {
                $hashes[] = ['kind' => 'edge_evidence', 'hash' => $hash];
            }
        }

        return collect($hashes)
            ->unique(fn (array $source): string => $source['kind'].':'.$source['hash'])
            ->values()
            ->map(static fn (array $source): array => [
                'schema_version' => self::SOURCE_SCHEMA,
                'source_kind' => $source['kind'],
                'source_hash' => $source['hash'],
            ])
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $entities
     * @param  Collection<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    private function quality(Collection $entities, Collection $edges): array
    {
        $stale = $entities->where('freshness_status', 'stale')->count();
        $weak = $entities->filter(fn (array $entity): bool => in_array($entity['freshness_status'] ?? '', ['weak', 'unknown'], true))->count();
        $unsourcedEntities = $entities
            ->filter(fn (array $entity): bool => ($entity['evidence_ref_hashes'] ?? []) === [] && ($entity['source_ref_hashes'] ?? []) === [])
            ->count();
        $unsourcedEdges = $edges
            ->filter(fn (array $edge): bool => ($edge['evidence_ref_hashes'] ?? []) === [])
            ->count();

        $blockers = [];
        $warnings = [];
        if ($entities->isEmpty()) {
            $blockers[] = 'no_reality_entities';
        }
        if ($stale > 0) {
            $warnings[] = 'stale_entities_present';
        }
        if ($weak > 0) {
            $warnings[] = 'weak_freshness_entities_present';
        }
        if ($unsourcedEntities > 0) {
            $warnings[] = 'unsourced_entities_present';
        }
        if ($unsourcedEdges > 0) {
            $warnings[] = 'unsourced_edges_present';
        }

        return [
            'status' => $blockers === [] && $warnings === [] ? 'passed' : ($blockers === [] ? 'watch' : 'blocked'),
            'stale_entities' => $stale,
            'weak_entities' => $weak,
            'unsourced_entities' => $unsourcedEntities,
            'unsourced_edges' => $unsourcedEdges,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function freshness(AtlasRealityEntity $entity): array
    {
        if ($entity->valid_until !== null && $entity->valid_until->isPast()) {
            return ['status' => 'stale'];
        }

        $status = (string) ($entity->freshness_status ?: 'unknown');

        return ['status' => $status === 'current' ? 'current' : $status];
    }

    private function confidence(string $authority, string $freshness, int $evidenceCount, int $sourceCount): float
    {
        $score = match ($authority) {
            'canonical', 'canonical_evidence' => 0.72,
            'system_observed' => 0.64,
            'operator_declared' => 0.56,
            default => 0.42,
        };
        $score += min(0.16, ($evidenceCount + $sourceCount) * 0.04);
        if ($freshness === 'current') {
            $score += 0.08;
        }
        if ($freshness === 'stale') {
            $score -= 0.20;
        }

        return round(max(0.0, min(1.0, $score)), 2);
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function status(array $quality, string $risk, int $entityCount): string
    {
        if ($entityCount === 0) {
            return in_array($risk, ['high', 'critical', 'irreversible'], true) ? 'blocked' : 'degraded';
        }
        if (($quality['blockers'] ?? []) !== []) {
            return 'blocked';
        }
        if (($quality['warnings'] ?? []) !== []) {
            return in_array($risk, ['high', 'critical', 'irreversible'], true) ? 'blocked' : 'watch';
        }

        return 'ready';
    }

    /**
     * @return array<string,mixed>
     */
    private function missingTablesPayload(string $risk, int $hours, int $limit): array
    {
        $status = in_array($risk, ['high', 'critical', 'irreversible'], true) ? 'blocked' : 'degraded';
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'window' => ['hours' => $hours, 'limit' => $limit],
            'summary' => [
                'entities_total' => 0,
                'edges_total' => 0,
                'sources_total' => 0,
                'stale_entities' => 0,
                'weak_entities' => 0,
                'unsourced_entities' => 0,
                'unsourced_edges' => 0,
            ],
            'entities' => [],
            'edges' => [],
            'sources' => [],
            'quality_gate' => [
                'status' => 'blocked',
                'risk_level' => $risk,
                'blockers' => ['reality_graph_tables_missing'],
                'warnings' => [],
            ],
            'claim_policy' => [
                'truth_graph_complete' => false,
                'recommendation_only' => true,
                'providers_invoked' => false,
                'writes' => false,
                'benchmark_run' => false,
                'external_execution_performed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    private function risk(mixed $risk): string
    {
        $risk = is_scalar($risk) ? strtolower(trim((string) $risk)) : 'low';

        return in_array($risk, ['low', 'medium', 'high', 'critical', 'irreversible'], true) ? $risk : 'low';
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        )));
    }
}
