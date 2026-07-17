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

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => self::KIND_MEASURE_FREEZE,
            'measure_id' => self::MEASURE_ID,
            'formula' => 'MAXA-06 fase 2: coverage_ratio = active code symbols whose provenance in atlas_code_symbol_embeddings (embedding_model + embedded_content_hash) matches current source_hash, over active code symbols. Stale = provenance stamped but source_hash drifted (incremental re-embed target). Missing = no embedding row for the symbol.',
            'formula_version' => self::FORMULA_VERSION,
            'thresholds' => [
                'target_coverage_ratio' => 1.0,
                'denominator_min_active_symbols' => 1,
                'stale_definition' => 'atlas_code_symbol_embeddings.embedded_content_hash != atlas_engineering_code_symbols.source_hash',
                'missing_definition' => 'no matching row in atlas_code_symbol_embeddings for symbol_id',
                'scope' => 'active_symbols_only',
                'default_switch' => 'off',
            ],
            'denominator_min' => 1,
            'ttl_days' => 60,
            'author_engine_id' => 'cursor-acos-max-maxa06-fase2',
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
            return $this->emptyReport('table_missing', 'atlas_engineering_code_symbols_missing', $freeze);
        }

        if (! DatabaseTableAvailability::has('atlas_code_symbol_embeddings')) {
            return $this->emptyReport('table_missing', 'atlas_code_symbol_embeddings_missing', $freeze);
        }

        $active = DB::table('atlas_engineering_code_symbols')
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('archived_at')
            ->count();

        if ($active === 0) {
            return array_merge(
                $this->emptyReport('insufficient_signal', 'no_active_code_symbols', $freeze),
                ['aggregate' => $this->emptyAggregate()],
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
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'freeze' => $freeze,
            'status' => $status,
            'reason' => $reason,
            'denominator_min' => (int) (AiValueNormalizer::finiteFloatOrNull($freeze['denominator_min'] ?? null) ?? 0),
            'aggregate' => [
                'active_symbols' => $active,
                'covered_count' => $covered,
                'stale_count' => $stale,
                'missing_count' => $missing,
                'coverage_ratio' => $ratio,
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
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'freeze' => $freeze,
            'status' => AiValueNormalizer::trimmedStringOrNull($status) ?? '',
            'reason' => AiValueNormalizer::trimmedStringOrNull($reason) ?? '',
            'denominator_min' => (int) (AiValueNormalizer::finiteFloatOrNull($freeze['denominator_min'] ?? null) ?? 0),
            'aggregate' => $this->emptyAggregate(),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyAggregate(): array
    {
        return [
            'active_symbols' => 0,
            'covered_count' => 0,
            'stale_count' => 0,
            'missing_count' => 0,
            'coverage_ratio' => null,
        ];
    }
}
