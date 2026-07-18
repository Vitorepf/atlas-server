<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

/**
 * MAXA-06 fase 2 — Semantic coverage ruler for `atlas_engineering_code_symbols`.
 *
 * The plan pins fase-2 aceite:
 *   - psql: count(embedding) coverage over active code symbols,
 *   - R8 corpus with code queries: precision@5 > lexical baseline,
 *   - incremental re-embed by `source_hash` (only new/changed rows).
 *
 * This service is the RULER (not the backfill and not the retriever): the
 * embedding switch itself stays default-OFF (fase-2 is a switch, per §168).
 * The ruler exposes: denominator = active code symbols, covered_count =
 * symbols whose embedding row exists in `atlas_code_symbol_embeddings` with
 * `embedded_content_hash == symbols.source_hash`, stale_count = symbols
 * whose embedding is stamped but source_hash drifted (incremental target),
 * missing_count = symbols with no embedding row.
 *
 * Provenance columns live on the SEPARATE `atlas_code_symbol_embeddings`
 * table so the 290k+ rows in `atlas_engineering_code_symbols` never carry a
 * nullable halfvec column. Stale-detection is deliberately provenance-based
 * (not vector-based) so the ruler is sqlite-testable without pgvector.
 *
 * READ-ONLY. Never embeds, never mutates, never routes.
 */
final class AtlasCodeSymbolEmbeddingCoverageService
{
    public const SCHEMA_VERSION = 'atlas.acos_max.code_symbol_embedding_coverage.v1';

    public const MEASURE_ID = 'atlas.code_symbol_embedding_coverage.v1';

    public const FORMULA_VERSION = 'code_symbol_embedding_coverage.v1';

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
    public const FIELD_ACTIVE_SYMBOLS = 'active_symbols';
    public const FIELD_COVERED_COUNT = 'covered_count';
    public const FIELD_STALE_COUNT = 'stale_count';
    public const FIELD_MISSING_COUNT = 'missing_count';
    public const FIELD_COVERAGE_RATIO = 'coverage_ratio';
    public const FIELD_KIND = 'kind';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_DEFAULT_SWITCH = 'default_switch';

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA => 'MAXA-06 fase 2: coverage_ratio = active code symbols whose provenance in atlas_code_symbol_embeddings (embedding_model + embedded_content_hash) matches current source_hash, over active code symbols. Stale = provenance stamped but source_hash drifted (incremental re-embed target). Missing = no embedding row for the symbol.',
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_THRESHOLDS => [
                'target_coverage_ratio' => 1.0,
                'denominator_min_active_symbols' => 1,
                'stale_definition' => 'atlas_code_symbol_embeddings.embedded_content_hash != atlas_engineering_code_symbols.source_hash',
                'missing_definition' => 'no matching row in atlas_code_symbol_embeddings for symbol_id',
                'scope' => 'active_symbols_only',
                self::FIELD_DEFAULT_SWITCH => 'off',
            ],
            self::FIELD_DENOMINATOR_MIN => 1,
            'ttl_days' => 60,
            self::FIELD_AUTHOR_ENGINE_ID => 'cursor-acos-max-maxa06-fase2',
            'judge_engine_id' => 'codex-independent-maxa06-fase2-judge',
            'dual_read_required' => false,
            'series_registry' => [
                'series' => self::MEASURE_ID,
                'path' => 'atlas:code:symbol-embedding-coverage --json',
                'source_type' => 'computed_reader_field',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $freeze = self::freezePayload();

        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return $this->emptyReport(self::STATUS_TABLE_MISSING, 'atlas_engineering_code_symbols_missing', $freeze);
        }

        if (! DatabaseTableAvailability::has('atlas_code_symbol_embeddings')) {
            return $this->emptyReport(self::STATUS_TABLE_MISSING, 'atlas_code_symbol_embeddings_missing', $freeze);
        }

        $active = DB::table('atlas_engineering_code_symbols')
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('archived_at')
            ->count();

        if ($active === 0) {
            return array_merge(
                $this->emptyReport(self::STATUS_INSUFFICIENT_SIGNAL, 'no_active_code_symbols', $freeze),
                [self::FIELD_AGGREGATE => $this->emptyAggregate()],
            );
        }

        $covered = DB::table('atlas_engineering_code_symbols as s')
            ->join('atlas_code_symbol_embeddings as e', 's.id', '=', 'e.symbol_id')
            ->where('s.status', self::STATUS_ACTIVE)
            ->whereNull('s.archived_at')
            ->whereColumn('e.embedded_content_hash', 's.source_hash')
            ->count();

        $stale = DB::table('atlas_engineering_code_symbols as s')
            ->join('atlas_code_symbol_embeddings as e', 's.id', '=', 'e.symbol_id')
            ->where('s.status', self::STATUS_ACTIVE)
            ->whereNull('s.archived_at')
            ->whereColumn('e.embedded_content_hash', '!=', 's.source_hash')
            ->count();

        // Missing = active symbols without ANY embedding row.
        $withEmbedding = DB::table('atlas_engineering_code_symbols as s')
            ->join('atlas_code_symbol_embeddings as e', 's.id', '=', 'e.symbol_id')
            ->where('s.status', self::STATUS_ACTIVE)
            ->whereNull('s.archived_at')
            ->distinct()
            ->count('s.id');
        $missing = max(0, $active - $withEmbedding);

        $ratio = round($covered / max(1, $active), 4);

        $status = $covered === $active
            ? self::STATUS_OK
            : ($covered === 0 ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_PARTIAL_COVERAGE);
        $reason = match ($status) {
            self::STATUS_OK => null,
            self::STATUS_INSUFFICIENT_SIGNAL => 'no_symbols_embedded_yet',
            self::STATUS_PARTIAL_COVERAGE => 'symbols_awaiting_backfill_or_re_embed',
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
                self::FIELD_ACTIVE_SYMBOLS => $active,
                self::FIELD_COVERED_COUNT => $covered,
                self::FIELD_STALE_COUNT => $stale,
                self::FIELD_MISSING_COUNT => $missing,
                self::FIELD_COVERAGE_RATIO => $ratio,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $freeze
     * @return array<string,mixed>
     */
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
            self::FIELD_ACTIVE_SYMBOLS => 0,
            self::FIELD_COVERED_COUNT => 0,
            self::FIELD_STALE_COUNT => 0,
            self::FIELD_MISSING_COUNT => 0,
            self::FIELD_COVERAGE_RATIO => null,
        ];
    }
}
