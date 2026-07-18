<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Semantic\EmbeddingProvenance;
use Throwable;

final class Maxa04JinaV3DualReadService
{
    public const CANDIDATE_MODEL = 'jinaai/jina-embeddings-v3';

    public const CANDIDATE_DIMENSIONS = 1024;

    public const PENDING_WINDOW = 'jina_v3_dual_read_benchmark_window';

    public const STATUS_PENDING_WINDOW = 'pending_window';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const CURRENT_MODEL_FALLBACK = 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2';

    public const MODE_SHADOW_ONLY = 'shadow_only';

    public const STATUS_MECHANISM_READY = 'mechanism_ready';

    public const STATUS_NO_DUAL_READ_CASES = 'no_dual_read_cases';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_SLICE = 'slice';
    public const FIELD_REASON = 'reason';
    public const FIELD_CASES = 'cases';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_WINDOW_BASIS = 'window_basis';
    public const FIELD_PROMOTION = 'promotion';
    public const FIELD_ROLLBACK = 'rollback';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_MODEL = 'model';
    public const FIELD_SERIES = 'series';
    public const FIELD_TARGETS_AVAILABLE = 'targets_available';
    public const FIELD_DUAL_READ_HASH = 'dual_read_hash';
    public const FIELD_MODEL_ID = 'model_id';
    public const FIELD_DIMENSIONS = 'dimensions';
    public const FIELD_DEFAULT_PROMOTED = 'default_promoted';
    public const FIELD_AB_GREEN_CLAIMED = 'ab_green_claimed';
    public const FIELD_CURRENT_MODEL = 'current_model';
    public const FIELD_CANDIDATE_MODEL = 'candidate_model';
    public const FIELD_CTX_TOKENS = 'ctx_tokens';
    public const FIELD_POOLING = 'pooling';
    public const FIELD_AB_GREEN_CLAIM_ALLOWED = 'ab_green_claim_allowed';
    public const FIELD_ALLOWED = 'allowed';
    public const FIELD_APPLIED_TO_LIVE = 'applied_to_live';
    public const FIELD_BASELINE = 'baseline';
    public const FIELD_BASIS = 'basis';
    public const FIELD_CANDIDATE = 'candidate';
    public const FIELD_CANDIDATE_PRECISION_AT_5 = 'candidate_precision_at_5';
    public const FIELD_CANDIDATE_PRECISION_AT_5_MEAN = 'candidate_precision_at_5_mean';
    public const FIELD_CANDIDATE_RECALL_AT_5 = 'candidate_recall_at_5';
    public const FIELD_CANDIDATE_RECALL_AT_5_MEAN = 'candidate_recall_at_5_mean';


    /** @return array<string,mixed> */
    public function plan(): array
    {
        $currentModel = AiValueNormalizer::trimmedScalarStringOrNull($this->configValue(
            'atlas.semantic_memory.semantic_rag_model',
            self::CURRENT_MODEL_FALLBACK,
        )) ?? self::CURRENT_MODEL_FALLBACK;

        return [
            self::FIELD_SCHEMA_VERSION => Maxa04JinaV3DualReadLedger::SCHEMA,
            self::FIELD_SLICE => 'MAXA-04',
            self::FIELD_STATUS => self::STATUS_MECHANISM_READY,
            self::FIELD_SERIES => Maxa04JinaV3DualReadLedger::SCHEMA,
            self::FIELD_CURRENT_MODEL => [
                self::FIELD_PROVIDER => 'semantic_rag',
                self::FIELD_MODEL => $currentModel,
                self::FIELD_MODEL_ID => EmbeddingProvenance::modelId([
                    self::FIELD_PROVIDER => 'semantic_rag',
                    self::FIELD_MODEL => $currentModel,
                ]),
                self::FIELD_DIMENSIONS => (int) $this->configValue('atlas.semantic_memory.embedding_dimensions', 384),
            ],
            self::FIELD_CANDIDATE_MODEL => [
                self::FIELD_PROVIDER => 'semantic_rag',
                self::FIELD_MODEL => self::CANDIDATE_MODEL,
                self::FIELD_MODEL_ID => EmbeddingProvenance::modelId([
                    self::FIELD_PROVIDER => 'semantic_rag',
                    self::FIELD_MODEL => self::CANDIDATE_MODEL,
                ]),
                self::FIELD_DIMENSIONS => self::CANDIDATE_DIMENSIONS,
                self::FIELD_CTX_TOKENS => 8192,
                self::FIELD_POOLING => 'mean',
                'multilingual_pt' => true,
            ],
            'dual_read' => [
                'required' => true,
                self::FIELD_BASELINE => 'current_embedding_model',
                self::FIELD_CANDIDATE => 'jina_v3_reembedded_shadow_index',
                'ledger_path' => $this->storagePath(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
                self::FIELD_AB_GREEN_CLAIM_ALLOWED => false,
            ],
            'reembed_path' => [
                'mode' => self::MODE_SHADOW_ONLY,
                'command' => 'ATLAS_SEMANTIC_RAG_MODEL=jinaai/jina-embeddings-v3 php artisan atlas:memory:embed-backfill --stale --json',
                'writes_live_default_model' => false,
                'requires_dual_read_ledger' => true,
            ],
            self::FIELD_ROLLBACK => [
                'handle' => 'maxa04:restore-current-semantic-rag-model',
                'description' => 'Restore ATLAS_SEMANTIC_RAG_MODEL to the prior model and discard jina-v3 shadow rows before any operator promotion.',
                'default_model_unchanged' => true,
            ],
            self::FIELD_DEFAULT_PROMOTED => false,
            self::FIELD_APPLIED_TO_LIVE => false,
            self::STATUS_PENDING_WINDOW => self::PENDING_WINDOW,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function evaluate(array $cases): array
    {
        $normalized = $this->normalizeCases($cases);
        $summary = $this->summary($normalized);
        $windowBasis = $this->windowBasis($summary);
        $status = $normalized === [] ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_PENDING_WINDOW;

        return [
            self::FIELD_SCHEMA_VERSION => Maxa04JinaV3DualReadLedger::SCHEMA,
            self::FIELD_SLICE => 'MAXA-04',
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $normalized === [] ? self::STATUS_NO_DUAL_READ_CASES : null,
            self::FIELD_WINDOW_BASIS => $windowBasis,
            self::FIELD_SUMMARY => $summary,
            self::FIELD_CASES => $normalized,
            self::FIELD_AB_GREEN_CLAIMED => false,
            self::FIELD_PROMOTION => $this->promotionBlock(),
            self::FIELD_ROLLBACK => $this->plan()[self::FIELD_ROLLBACK],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function record(array $cases, ?Maxa04JinaV3DualReadLedger $ledger = null): array
    {
        $report = $this->evaluate($cases);
        $receipt = [
            self::FIELD_SCHEMA_VERSION => Maxa04JinaV3DualReadLedger::SCHEMA,
            self::FIELD_SERIES => Maxa04JinaV3DualReadLedger::SCHEMA,
            self::FIELD_SLICE => 'MAXA-04',
            'recorded_at' => now()->toJSON(),
            self::FIELD_STATUS => $report[self::FIELD_STATUS],
            self::FIELD_WINDOW_BASIS => $report[self::FIELD_WINDOW_BASIS],
            self::FIELD_SUMMARY => $report[self::FIELD_SUMMARY],
            self::FIELD_AB_GREEN_CLAIMED => false,
            self::FIELD_PROMOTION => $report[self::FIELD_PROMOTION],
            self::FIELD_DUAL_READ_HASH => hash('sha256', (string) json_encode($report[self::FIELD_CASES], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
        ];

        ($ledger ?? new Maxa04JinaV3DualReadLedger)->append($receipt);

        return $report + ['ledger_recorded' => true, self::FIELD_DUAL_READ_HASH => $receipt[self::FIELD_DUAL_READ_HASH]];
    }

    /** @param list<array<string,mixed>> $cases @return list<array<string,mixed>> */
    private function normalizeCases(array $cases): array
    {
        $normalized = [];
        foreach ($cases as $case) {
            $queryId = AiValueNormalizer::trimmedStringOrNull($case['query_id'] ?? $case['id'] ?? null) ?? '';
            if ($queryId === '') {
                continue;
            }

            $normalized[] = [
                'query_id' => $queryId,
                'current_recall_at_5' => $this->unitOrNull($case['current_recall_at_5'] ?? null),
                self::FIELD_CANDIDATE_RECALL_AT_5 => $this->unitOrNull($case[self::FIELD_CANDIDATE_RECALL_AT_5] ?? null),
                'current_precision_at_5' => $this->unitOrNull($case['current_precision_at_5'] ?? null),
                self::FIELD_CANDIDATE_PRECISION_AT_5 => $this->unitOrNull($case[self::FIELD_CANDIDATE_PRECISION_AT_5] ?? null),
                self::FIELD_TARGETS_AVAILABLE => max(0, (int) (AiValueNormalizer::finiteFloatOrNull($case[self::FIELD_TARGETS_AVAILABLE] ?? null) ?? 0)),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $cases @return array<string,mixed> */
    private function summary(array $cases): array
    {
        $count = count($cases);
        $targets = array_sum(array_map(static fn (array $case): int => (int) (AiValueNormalizer::finiteFloatOrNull($case[self::FIELD_TARGETS_AVAILABLE] ?? null) ?? 0), $cases));

        return [
            self::FIELD_CASES => $count,
            self::FIELD_TARGETS_AVAILABLE => $targets,
            'current_recall_at_5_mean' => $this->mean($cases, 'current_recall_at_5'),
            self::FIELD_CANDIDATE_RECALL_AT_5_MEAN => $this->mean($cases, 'candidate_recall_at_5'),
            'current_precision_at_5_mean' => $this->mean($cases, 'current_precision_at_5'),
            self::FIELD_CANDIDATE_PRECISION_AT_5_MEAN => $this->mean($cases, 'candidate_precision_at_5'),
        ];
    }

    /** @param array<string,mixed> $summary */
    private function windowBasis(array $summary): string
    {
        if ((int) (AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_CASES] ?? null) ?? 0) <= 0) {
            return self::STATUS_NO_DUAL_READ_CASES;
        }

        $candidateRecall = AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_CANDIDATE_RECALL_AT_5_MEAN] ?? null);
        $currentRecall = AiValueNormalizer::finiteFloatOrNull($summary['current_recall_at_5_mean'] ?? null);
        $candidatePrecision = AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_CANDIDATE_PRECISION_AT_5_MEAN] ?? null);
        $currentPrecision = AiValueNormalizer::finiteFloatOrNull($summary['current_precision_at_5_mean'] ?? null);

        $recallOk = $candidateRecall !== null
            && $currentRecall !== null
            && $candidateRecall >= $currentRecall;
        $precisionOk = $candidatePrecision !== null
            && $currentPrecision !== null
            && $candidatePrecision >= $currentPrecision;

        return $recallOk && $precisionOk
            ? 'candidate_non_regression_observed'
            : 'candidate_regression_or_unmeasured';
    }

    /** @return array<string,mixed> */
    private function promotionBlock(): array
    {
        return [
            self::FIELD_ALLOWED => false,
            self::FIELD_BASIS => self::STATUS_PENDING_WINDOW,
            self::FIELD_DEFAULT_PROMOTED => false,
            'live_flip_performed' => false,
            self::FIELD_REASON => 'MAXA-04 only lands the dual-read/re-embed mechanism; promotion requires a later operator-reviewed benchmark window.',
        ];
    }

    private function unitOrNull(mixed $value): ?float
    {
        $float = AiValueNormalizer::finiteFloatOrNull($value);

        return $float === null ? null : round(AiValueNormalizer::clampUnit($float), 6);
    }

    /** @param list<array<string,mixed>> $cases */
    private function mean(array $cases, string $field): ?float
    {
        $values = [];
        foreach ($cases as $case) {
            $float = AiValueNormalizer::finiteFloatOrNull($case[$field] ?? null);
            if ($float !== null) {
                $values[] = $float;
            }
        }

        return $values === [] ? null : round(array_sum($values) / count($values), 6);
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private function storagePath(string $path): string
    {
        try {
            return storage_path($path);
        } catch (Throwable) {
            return 'storage/'.$path;
        }
    }
}
