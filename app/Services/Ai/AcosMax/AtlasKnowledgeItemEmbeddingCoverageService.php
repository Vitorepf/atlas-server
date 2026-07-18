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
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const SCHEMA_VERSION = 'atlas.acos_max.kb_embedding_coverage.v1';

    public const MEASURE_ID = 'atlas.kb_embedding_coverage.v1';

    public const FORMULA_VERSION = 'kb_embedding_coverage.v1';

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const STATUS_PARTIAL_COVERAGE = 'partial_coverage';

    public const STATUS_TABLE_MISSING = 'table_missing';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_AGGREGATE = 'aggregate';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_ACTIVE_ITEMS = 'active_items';
    public const FIELD_COVERED_COUNT = 'covered_count';
    public const FIELD_STALE_COUNT = 'stale_count';
    public const FIELD_MISSING_COUNT = 'missing_count';
    public const FIELD_COVERAGE_RATIO = 'coverage_ratio';
    public const FIELD_KIND = 'kind';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_DENOMINATOR_MIN_ACTIVE_ITEMS = 'denominator_min_active_items';
    public const FIELD_MISSING_DEFINITION = 'missing_definition';
    public const FIELD_PATH = 'path';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_SERIES = 'series';

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA => 'MAXA-06 fase 1: coverage_ratio = active items whose MAXA-03 provenance (embedding_model + embedded_content_hash) matches current content_hash, over active items. Stale = provenance stamped but hash drifted. Missing = no provenance.',
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_THRESHOLDS => [
                'target_coverage_ratio' => 1.0,
                self::FIELD_DENOMINATOR_MIN_ACTIVE_ITEMS => 1,
                'stale_definition' => 'embedded_content_hash != content_hash',
                self::FIELD_MISSING_DEFINITION => 'embedding_model IS NULL OR embedded_content_hash IS NULL',
                self::FIELD_SCOPE => 'active_items_only',
            ],
            self::FIELD_DENOMINATOR_MIN => 1,
            'ttl_days' => 60,
            self::FIELD_AUTHOR_ENGINE_ID => 'cursor-acos-max-maxa06-fase1',
            self::FIELD_JUDGE_ENGINE_ID => 'codex-independent-maxa06-fase1-judge',
            self::FIELD_DUAL_READ_REQUIRED => false,
            'series_registry' => [
                self::FIELD_SERIES => self::MEASURE_ID,
                self::FIELD_PATH => 'atlas:memory:kb-embedding-coverage --json',
                'source_type' => 'computed_reader_field',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $freeze = self::freezePayload();

        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return $this->emptyReport(self::STATUS_TABLE_MISSING, 'atlas_engineering_knowledge_items_missing', $freeze);
        }

        $columnsReady = DatabaseTableAvailability::hasColumn('atlas_engineering_knowledge_items', 'embedding_model')
            && DatabaseTableAvailability::hasColumn('atlas_engineering_knowledge_items', 'embedded_content_hash');

        if (! $columnsReady) {
            return $this->emptyReport('columns_missing', 'provenance_columns_not_migrated', $freeze);
        }

        $baseQuery = AtlasEngineeringKnowledgeItem::query()
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('archived_at');

        $active = (clone $baseQuery)->count();

        if ($active === 0) {
            return array_merge($this->emptyReport(self::STATUS_INSUFFICIENT_SIGNAL, 'no_active_knowledge_items', $freeze), [
                self::FIELD_AGGREGATE => $this->emptyAggregate(),
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
            ? self::STATUS_OK
            : ($covered === 0 ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_PARTIAL_COVERAGE);
        $reason = match ($status) {
            self::STATUS_OK => null,
            self::STATUS_INSUFFICIENT_SIGNAL => 'no_items_embedded_yet',
            self::STATUS_PARTIAL_COVERAGE => 'items_awaiting_backfill_or_re_embed',
            default => null,
        };

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_GENERATED_AT => now('UTC')->toIso8601String(),
            self::FIELD_FREEZE => $freeze,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $reason,
            self::FIELD_DENOMINATOR_MIN => (int) (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_DENOMINATOR_MIN] ?? null) ?? 0),
            self::FIELD_AGGREGATE => [
                self::FIELD_ACTIVE_ITEMS => $active,
                self::FIELD_COVERED_COUNT => $covered,
                self::FIELD_STALE_COUNT => $stale,
                self::FIELD_MISSING_COUNT => $missing,
                self::FIELD_COVERAGE_RATIO => $ratio,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $status, string $reason, array $freeze): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_GENERATED_AT => now('UTC')->toIso8601String(),
            self::FIELD_FREEZE => $freeze,
            self::FIELD_STATUS => AiValueNormalizer::trimmedStringOrNull($status) ?? '',
            self::FIELD_REASON => AiValueNormalizer::trimmedStringOrNull($reason) ?? '',
            self::FIELD_DENOMINATOR_MIN => (int) (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_DENOMINATOR_MIN] ?? null) ?? 0),
            self::FIELD_AGGREGATE => $this->emptyAggregate(),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyAggregate(): array
    {
        return [
            self::FIELD_ACTIVE_ITEMS => 0,
            self::FIELD_COVERED_COUNT => 0,
            self::FIELD_STALE_COUNT => 0,
            self::FIELD_MISSING_COUNT => 0,
            self::FIELD_COVERAGE_RATIO => null,
        ];
    }
}
