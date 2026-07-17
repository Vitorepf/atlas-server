<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * MAXA-06 fase 1 — Semantic coverage ruler for `atlas_engineering_knowledge_items`.
 *
 * The plan pins as fase-1 aceite:
 *   - psql: count(embedding) == count(*) on the KB table,
 *   - R8 corpus with ≥10 KB queries: precision@5 > lexical baseline.
 *
 * This service is the RULER (not the backfill and not the retriever): it reads
 * the table and publishes:
 *   - denominator (active items),
 *   - covered_count (items whose MAXA-03 provenance is present AND the
 *     `embedded_content_hash` matches the current `content_hash`),
 *   - stale_count (items whose provenance exists but the content_hash drifted
 *     from the embedded_content_hash — MAXA-03 incremental re-embed target),
 *   - missing_count (items with no provenance stamped yet),
 *   - coverage_ratio = covered_count / denominator (null when denominator=0).
 *
 * Stale-detection is deliberately provenance-based, not vector-based: the DB
 * column `embedded_content_hash` is added on ALL drivers (including sqlite) by
 * the fase-1 migration, so the ruler is sqlite-testable without pgvector. The
 * actual `embedding` halfvec + HNSW index remain pgsql-only.
 *
 * READ-ONLY. Never embeds, never mutates, never routes. Fase-2 (code symbols)
 * is a separate slice; fase-1 lives here.
 */
final class AtlasKnowledgeItemEmbeddingCoverageService
{
    public const SCHEMA_VERSION = 'atlas.acos_max.kb_embedding_coverage.v1';

    public const MEASURE_ID = 'atlas.kb_embedding_coverage.v1';

    public const FORMULA_VERSION = 'kb_embedding_coverage.v1';

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula' => 'MAXA-06 fase 1: coverage_ratio = active items whose MAXA-03 provenance (embedding_model + embedded_content_hash) matches current content_hash, over active items. Stale = provenance stamped but hash drifted. Missing = no provenance.',
            'formula_version' => self::FORMULA_VERSION,
            'thresholds' => [
                'target_coverage_ratio' => 1.0,
                'denominator_min_active_items' => 1,
                'stale_definition' => 'embedded_content_hash != content_hash',
                'missing_definition' => 'embedding_model IS NULL OR embedded_content_hash IS NULL',
                'scope' => 'active_items_only',
            ],
            'denominator_min' => 1,
            'ttl_days' => 60,
            'author_engine_id' => 'cursor-acos-max-maxa06-fase1',
            'judge_engine_id' => 'codex-independent-maxa06-fase1-judge',
            'dual_read_required' => false,
            'series_registry' => [
                'series' => self::MEASURE_ID,
                'path' => 'atlas:memory:kb-embedding-coverage --json',
                'source_type' => 'computed_reader_field',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $freeze = self::freezePayload();

        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return $this->emptyReport('table_missing', 'atlas_engineering_knowledge_items_missing', $freeze);
        }

        $columnsReady = DatabaseTableAvailability::hasColumn('atlas_engineering_knowledge_items', 'embedding_model')
            && DatabaseTableAvailability::hasColumn('atlas_engineering_knowledge_items', 'embedded_content_hash');

        if (! $columnsReady) {
            return $this->emptyReport('columns_missing', 'provenance_columns_not_migrated', $freeze);
        }

        $baseQuery = AtlasEngineeringKnowledgeItem::query()
            ->where('status', 'active')
            ->whereNull('archived_at');

        $active = (clone $baseQuery)->count();

        if ($active === 0) {
            return array_merge($this->emptyReport('insufficient_signal', 'no_active_knowledge_items', $freeze), [
                'aggregate' => $this->emptyAggregate(),
            ]);
        }

        $covered = (clone $baseQuery)
            ->whereNotNull('embedding_model')
            ->whereNotNull('embedded_content_hash')
            ->whereColumn('embedded_content_hash', 'content_hash')
            ->count();

        $stale = (clone $baseQuery)
            ->whereNotNull('embedding_model')
            ->whereNotNull('embedded_content_hash')
            ->whereColumn('embedded_content_hash', '!=', 'content_hash')
            ->count();

        $missing = (clone $baseQuery)
            ->where(function ($q): void {
                $q->whereNull('embedding_model')
                    ->orWhereNull('embedded_content_hash');
            })
            ->count();

        $ratio = round($covered / max(1, $active), 4);

        $status = $covered === $active
            ? 'ok'
            : ($covered === 0 ? 'insufficient_signal' : 'partial_coverage');
        $reason = match ($status) {
            'ok' => null,
            'insufficient_signal' => 'no_items_embedded_yet',
            'partial_coverage' => 'items_awaiting_backfill_or_re_embed',
            default => null,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'freeze' => $freeze,
            'status' => $status,
            'reason' => $reason,
            'denominator_min' => (int) $freeze['denominator_min'],
            'aggregate' => [
                'active_items' => $active,
                'covered_count' => $covered,
                'stale_count' => $stale,
                'missing_count' => $missing,
                'coverage_ratio' => $ratio,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $status, string $reason, array $freeze): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'freeze' => $freeze,
            'status' => AiValueNormalizer::trimmedStringOrNull($status) ?? '',
            'reason' => AiValueNormalizer::trimmedStringOrNull($reason) ?? '',
            'denominator_min' => (int) $freeze['denominator_min'],
            'aggregate' => $this->emptyAggregate(),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyAggregate(): array
    {
        return [
            'active_items' => 0,
            'covered_count' => 0,
            'stale_count' => 0,
            'missing_count' => 0,
            'coverage_ratio' => null,
        ];
    }
}
