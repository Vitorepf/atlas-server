<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Context\LocalRagPrecisionCorpusService;
use Throwable;

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
    public const SCHEMA_VERSION = 'atlas.cognition.rerank_guard.v1';

    /** Float tolerance so equal precision counts as non-regression. */
    private const EPSILON = 0.0005;

    private string $baselinePath;

    public function __construct(
        private readonly ?LocalRagPrecisionCorpusService $corpus = null,
        ?string $baselinePath = null,
    ) {
        $this->baselinePath = $baselinePath ?? (function_exists('storage_path')
            ? storage_path('atlas/consolidation/rerank_baseline.json')
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
            return ['ok' => false, 'reason' => 'unmeasured (semantic engine/venv ausente) — nada a congelar'];
        }

        $baseline = [
            'schema_version' => self::SCHEMA_VERSION,
            'precision_at_k' => round($precision, 4),
            'frozen_at' => gmdate('c'),
        ];
        $baseline['hash'] = 'sha256:'.hash('sha256', (string) json_encode(['p' => $baseline['precision_at_k']]));

        try {
            $dir = dirname($this->baselinePath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($this->baselinePath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'falha ao gravar baseline'];
        }

        return ['ok' => true] + $baseline;
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
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'promote_allowed' => $verdict === 'promote_allowed',
            'current_precision_at_k' => $current,
            'baseline_precision_at_k' => $baseline,
            'label' => 'refatoracao',
        ];
    }

    /**
     * Pure decision: current must not regress below the frozen baseline.
     */
    public function evaluate(?float $current, ?float $baseline): string
    {
        if ($baseline === null) {
            return 'no_baseline';
        }
        if ($current === null) {
            return 'unmeasured';
        }

        return $current + self::EPSILON >= $baseline ? 'promote_allowed' : 'blocked_regression';
    }

    private function currentPrecision(): ?float
    {
        if ($this->corpus === null) {
            return null;
        }
        try {
            $report = $this->corpus->report();
            $status = (string) ($report['status'] ?? '');
            // The corpus degrades to a non-`ok` status when the engine is absent.
            if ($status !== '' && $status !== 'ok' && $status !== 'healthy') {
                return null;
            }
            $pAtK = (array) data_get($report, 'metrics.precision_at_k', []);
            $primary = data_get($report, 'metrics.primary_k', array_key_first($pAtK));
            $value = $pAtK[(string) $primary] ?? null;

            return $value === null ? null : (float) $value;
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

            return isset($decoded['precision_at_k']) ? (float) $decoded['precision_at_k'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
