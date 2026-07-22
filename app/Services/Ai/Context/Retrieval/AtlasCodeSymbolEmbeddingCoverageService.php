<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Retrieval;

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
    public const FIELD_DENOMINATOR_MIN_ACTIVE_SYMBOLS = 'denominator_min_active_symbols';
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
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
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_MISSING_DEFINITION = 'missing_definition';
    public const FIELD_PATH = 'path';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_SERIES = 'series';
    public const FIELD_SERIES_REGISTRY = 'series_registry';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_STALE_DEFINITION = 'stale_definition';
    public const FIELD_TARGET_COVERAGE_RATIO = 'target_coverage_ratio';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_ACTIVE_SYMBOLS_ONLY = 'active_symbols_only';
    public const FIELD_ARCHIVED_AT = 'archived_at';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS = 'atlas_code_symbol_embeddings';
    public const FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS = 'atlas_engineering_code_symbols';
    public const FIELD_COMPUTED_READER_FIELD = 'computed_reader_field';
    public const FIELD_NO_SYMBOLS_EMBEDDED_YET = 'no_symbols_embedded_yet';
    public const FIELD_OFF = 'off';
    public const FIELD_SYMBOLS_AWAITING_BACKFILL_OR_RE_EMBED = 'symbols_awaiting_backfill_or_re_embed';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_MISSING = 'atlas_code_symbol_embeddings_missing';
    public const FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_MISSING = 'atlas_engineering_code_symbols_missing';
    public const FIELD_NO_ACTIVE_CODE_SYMBOLS = 'no_active_code_symbols';
    public const FIELD_UTC = 'UTC';
    public const FIELD_S_ID = 's.id';
    public const FIELD_E_SYMBOL_ID = 'e.symbol_id';
    public const FIELD_S_ARCHIVED_AT = 's.archived_at';
    public const FIELD_S_STATUS = 's.status';
    public const FIELD_E_EMBEDDED_CONTENT_HASH = 'e.embedded_content_hash';
    public const FIELD_S_SOURCE_HASH = 's.source_hash';
    public const FIELD_CODEX_INDEPENDENT_MAXA06_FASE2_JUDGE = 'codex-independent-maxa06-fase2-judge';
    public const FIELD_CURSOR_ACOS_MAX_MAXA06_FASE2 = 'cursor-acos-max-maxa06-fase2';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE___JSON = 'atlas:code:symbol-embedding-coverage --json';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_AS_E = 'atlas_code_symbol_embeddings as e';
    public const FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_AS_S = 'atlas_engineering_code_symbols as s';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_EMBEDDED_CONTENT_HASH____ATLAS_ENGINEERING_CODE_SYMBOLS_SOURCE_HASH = 'atlas_code_symbol_embeddings.embedded_content_hash != atlas_engineering_code_symbols.source_hash';
    public const FIELD_NO_MATCHING_ROW_IN_ATLAS_CODE_SYMBOL_EMBEDDINGS_FOR_SYMBOL_ID = 'no matching row in atlas_code_symbol_embeddings for symbol_id';
    public const FLOAT_1_0 = 1.0;
    public const INT_60 = 60;

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA => 'MAXA-06 fase 2: coverage_ratio = active code symbols whose provenance in atlas_code_symbol_embeddings (embedding_model + embedded_content_hash) matches current source_hash, over active code symbols. Stale = provenance stamped but source_hash drifted (incremental re-embed target). Missing = no embedding row for the symbol.',
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_THRESHOLDS => [
                self::FIELD_TARGET_COVERAGE_RATIO => self::FLOAT_1_0,
                self::FIELD_DENOMINATOR_MIN_ACTIVE_SYMBOLS => 1,
                self::FIELD_STALE_DEFINITION => self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_EMBEDDED_CONTENT_HASH____ATLAS_ENGINEERING_CODE_SYMBOLS_SOURCE_HASH,
                self::FIELD_MISSING_DEFINITION => self::FIELD_NO_MATCHING_ROW_IN_ATLAS_CODE_SYMBOL_EMBEDDINGS_FOR_SYMBOL_ID,
                self::FIELD_SCOPE => self::FIELD_ACTIVE_SYMBOLS_ONLY,
                self::FIELD_DEFAULT_SWITCH => self::FIELD_OFF,
            ],
            self::FIELD_DENOMINATOR_MIN => 1,
            self::FIELD_TTL_DAYS => self::INT_60,
            self::FIELD_AUTHOR_ENGINE_ID => self::FIELD_CURSOR_ACOS_MAX_MAXA06_FASE2,
            self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_INDEPENDENT_MAXA06_FASE2_JUDGE,
            self::FIELD_DUAL_READ_REQUIRED => false,
            self::FIELD_SERIES_REGISTRY => [
                self::FIELD_SERIES => self::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE___JSON,
                self::FIELD_SOURCE_TYPE => self::FIELD_COMPUTED_READER_FIELD,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $freeze = self::freezePayload();

        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS)) {
            return $this->emptyReport(self::STATUS_TABLE_MISSING, self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_MISSING, $freeze);
        }

        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS)) {
            return $this->emptyReport(self::STATUS_TABLE_MISSING, self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_MISSING, $freeze);
        }

        $active = DB::table(self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS)
            ->where(self::FIELD_STATUS, self::STATUS_ACTIVE)
            ->whereNull(self::FIELD_ARCHIVED_AT)
            ->count();

        if ($active === 0) {
            return array_merge(
                $this->emptyReport(self::STATUS_INSUFFICIENT_SIGNAL, self::FIELD_NO_ACTIVE_CODE_SYMBOLS, $freeze),
                [self::FIELD_AGGREGATE => $this->emptyAggregate()],
            );
        }

        $covered = DB::table(self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_AS_S)
            ->join(self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_AS_E, self::FIELD_S_ID, '=', self::FIELD_E_SYMBOL_ID)
            ->where(self::FIELD_S_STATUS, self::STATUS_ACTIVE)
            ->whereNull(self::FIELD_S_ARCHIVED_AT)
            ->whereColumn(self::FIELD_E_EMBEDDED_CONTENT_HASH, self::FIELD_S_SOURCE_HASH)
            ->count();

        $stale = DB::table(self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_AS_S)
            ->join(self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_AS_E, self::FIELD_S_ID, '=', self::FIELD_E_SYMBOL_ID)
            ->where(self::FIELD_S_STATUS, self::STATUS_ACTIVE)
            ->whereNull(self::FIELD_S_ARCHIVED_AT)
            ->whereColumn(self::FIELD_E_EMBEDDED_CONTENT_HASH, '!=', self::FIELD_S_SOURCE_HASH)
            ->count();

        // Missing = active symbols without ANY embedding row.
        $withEmbedding = DB::table(self::FIELD_ATLAS_ENGINEERING_CODE_SYMBOLS_AS_S)
            ->join(self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDINGS_AS_E, self::FIELD_S_ID, '=', self::FIELD_E_SYMBOL_ID)
            ->where(self::FIELD_S_STATUS, self::STATUS_ACTIVE)
            ->whereNull(self::FIELD_S_ARCHIVED_AT)
            ->distinct()
            ->count(self::FIELD_S_ID);
        $missing = max(0, $active - $withEmbedding);

        $ratio = round($covered / max(1, $active), 4);

        $status = $covered === $active
            ? self::STATUS_OK
            : ($covered === 0 ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_PARTIAL_COVERAGE);
        $reason = match ($status) {
            self::STATUS_OK => null,
            self::STATUS_INSUFFICIENT_SIGNAL => self::FIELD_NO_SYMBOLS_EMBEDDED_YET,
            self::STATUS_PARTIAL_COVERAGE => self::FIELD_SYMBOLS_AWAITING_BACKFILL_OR_RE_EMBED,
            default => null,
        };

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_GENERATED_AT => now(self::FIELD_UTC)->toIso8601String(),
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
            self::FIELD_GENERATED_AT => now(self::FIELD_UTC)->toIso8601String(),
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
