<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Context\LocalRagPrecisionCorpusService;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;
use App\Support\UtcIsoTimestamp;

/**
 * T4-S7 (Obra #17) — the consolidation re-ranker NON-REGRESSION guard.
 *
 * Nightly consolidation may re-rank/retrain the retriever, but the pétrea gate
 * is "precision@k NUNCA regride": a consolidation is only promoted (etiqueta
 * `refatoracao`) when precision@k on a FROZEN control set is >= the frozen
 * baseline. This is the load-bearing SAFETY mechanism; the actual re-ranker
 * retrain runs on the semantic_rag venv (the ML follow-up), and this guard
 * refuses to let it land if it makes retrieval worse.
 *
 * Metric source: {@see LocalRagPrecisionCorpusService::report()} (real
 * precision@k over the corpus; degrades to `unmeasured` when the semantic
 * engine/venv is absent — never a fabricated pass). Baseline is hash-stamped on
 * disk so it can't be silently softened.
 */
final class AtlasConsolidationRerankGuard
{
    public const FIELD_HASH = 'hash';
    public const FIELD_LABEL = 'label';
    public const SCHEMA_VERSION = 'atlas.cognition.rerank_guard.v1';

    /** Float tolerance so equal precision counts as non-regression. */
    public const EPSILON = 0.0005;

    public const STATUS_NO_BASELINE = 'no_baseline';

    public const STATUS_UNMEASURED = 'unmeasured';

    public const FIELD_OK = 'ok';
    public const FIELD_PRECISION_AT_K = 'precision_at_k';
    public const FIELD_REASON = 'reason';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CURRENT_PRECISION_AT_K = 'current_precision_at_k';
    public const FIELD_BASELINE_PRECISION_AT_K = 'baseline_precision_at_k';

    public const STATUS_OK = 'ok';

    public const STATUS_HEALTHY = 'healthy';
    public const FIELD_FROZEN_AT = 'frozen_at';
    public const FIELD_PROMOTE_ALLOWED = 'promote_allowed';
    public const FIELD_STATUS = 'status';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_REFATORACAO = 'refatoracao';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_STORAGE_PATH = 'storage_path';
    public const FIELD_BLOCKED_REGRESSION = 'blocked_regression';
    public const FIELD_METRICS_PRECISION_AT_K = 'metrics.precision_at_k';
    public const FIELD_METRICS_PRIMARY_K = 'metrics.primary_k';
    public const FIELD_ATLAS_CONSOLIDATION_RERANK_BASELINE_JSON = 'atlas/consolidation/rerank_baseline.json';
    public const FIELD_FALHA_AO_GRAVAR_BASELINE = 'falha ao gravar baseline';
    public const FIELD_UNMEASURED__SEMANTIC_ENGINE_VENV_AUSENTE____NADA_A_CONGELAR = 'unmeasured (semantic engine/venv ausente) — nada a congelar';


    private string $baselinePath;

    public function __construct(
        private readonly ?LocalRagPrecisionCorpusService $corpus = null,
        ?string $baselinePath = null,
    ) {
        $this->baselinePath = $baselinePath ?? (function_exists(self::FIELD_STORAGE_PATH)
            ? storage_path(self::FIELD_ATLAS_CONSOLIDATION_RERANK_BASELINE_JSON)
            : sys_get_temp_dir().'/atlas/consolidation/rerank_baseline.json');
    }

    /**
     * Freeze the current precision@k as the non-regression baseline.
     *
     * @return array<string,mixed>
     */
    public function freeze(?float $precisionOverride = null): array
    {
        $precision = $precisionOverride ?? $this->currentPrecision();
        if ($precision === null) {
            return [self::FIELD_OK => false, self::FIELD_REASON => self::FIELD_UNMEASURED__SEMANTIC_ENGINE_VENV_AUSENTE____NADA_A_CONGELAR];
        }

        $baseline = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_PRECISION_AT_K => round($precision, 4),
            self::FIELD_FROZEN_AT => UtcIsoTimestamp::now(),
        ];
        $baseline[self::FIELD_HASH] = 'sha256:'.hash(self::FIELD_SHA256, (string) json_encode(['p' => $baseline[self::FIELD_PRECISION_AT_K]]));

        try {
            $dir = dirname($this->baselinePath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($this->baselinePath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            return [self::FIELD_OK => false, self::FIELD_REASON => self::FIELD_FALHA_AO_GRAVAR_BASELINE];
        }

        return [self::FIELD_OK => true] + $baseline;
    }

    /**
     * Verdict for promoting a consolidation. Never fabricates a pass: no
     * baseline → `no_baseline`; no metric (venv absent) → `unmeasured`.
     *
     * @return array<string,mixed>
     */
    public function verdict(?float $currentOverride = null): array
    {
        $baseline = $this->readBaseline();
        $current = $currentOverride ?? $this->currentPrecision();

        $verdict = $this->evaluate($current, $baseline);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_VERDICT => $verdict,
            self::FIELD_PROMOTE_ALLOWED => $verdict === self::FIELD_PROMOTE_ALLOWED,
            self::FIELD_CURRENT_PRECISION_AT_K => $current,
            self::FIELD_BASELINE_PRECISION_AT_K => $baseline,
            self::FIELD_LABEL => self::FIELD_REFATORACAO,
        ];
    }

    /**
     * Pure decision: current must not regress below the frozen baseline.
     */
    public function evaluate(?float $current, ?float $baseline): string
    {
        if ($baseline === null) {
            return self::STATUS_NO_BASELINE;
        }
        if ($current === null) {
            return self::STATUS_UNMEASURED;
        }

        return $current + self::EPSILON >= $baseline ? self::FIELD_PROMOTE_ALLOWED : self::FIELD_BLOCKED_REGRESSION;
    }

    private function currentPrecision(): ?float
    {
        if ($this->corpus === null) {
            return null;
        }
        try {
            $report = $this->corpus->report();
            $status = (AiValueNormalizer::trimmedStringOrNull($report[self::FIELD_STATUS] ?? null) ?? '');
            // The corpus degrades to a non-`ok` status when the engine is absent.
            if ($status !== '' && $status !== self::STATUS_OK && $status !== self::STATUS_HEALTHY) {
                return null;
            }
            $pAtK = AiValueNormalizer::arrayOrEmpty(data_get($report, self::FIELD_METRICS_PRECISION_AT_K, []));
            $primary = data_get($report, self::FIELD_METRICS_PRIMARY_K, array_key_first($pAtK));
            $value = $pAtK[AiValueNormalizer::trimmedScalarStringOrNull($primary) ?? ''] ?? null;

            return AiValueNormalizer::finiteFloatOrNull($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function readBaseline(): ?float
    {
        try {
            if (! is_file($this->baselinePath)) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($this->baselinePath), true, 512, JSON_THROW_ON_ERROR);

            return AiValueNormalizer::finiteFloatOrNull($decoded[self::FIELD_PRECISION_AT_K] ?? null);
        } catch (Throwable) {
            return null;
        }
    }
}
