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

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $currentModel = (string) $this->configValue(
            'atlas.semantic_memory.semantic_rag_model',
            'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
        );

        return [
            'schema_version' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'slice' => 'MAXA-04',
            'status' => 'mechanism_ready',
            'series' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'current_model' => [
                'provider' => 'semantic_rag',
                'model' => $currentModel,
                'model_id' => EmbeddingProvenance::modelId([
                    'provider' => 'semantic_rag',
                    'model' => $currentModel,
                ]),
                'dimensions' => (int) $this->configValue('atlas.semantic_memory.embedding_dimensions', 384),
            ],
            'candidate_model' => [
                'provider' => 'semantic_rag',
                'model' => self::CANDIDATE_MODEL,
                'model_id' => EmbeddingProvenance::modelId([
                    'provider' => 'semantic_rag',
                    'model' => self::CANDIDATE_MODEL,
                ]),
                'dimensions' => self::CANDIDATE_DIMENSIONS,
                'ctx_tokens' => 8192,
                'pooling' => 'mean',
                'multilingual_pt' => true,
            ],
            'dual_read' => [
                'required' => true,
                'baseline' => 'current_embedding_model',
                'candidate' => 'jina_v3_reembedded_shadow_index',
                'ledger_path' => $this->storagePath(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
                'ab_green_claim_allowed' => false,
            ],
            'reembed_path' => [
                'mode' => 'shadow_only',
                'command' => 'ATLAS_SEMANTIC_RAG_MODEL=jinaai/jina-embeddings-v3 php artisan atlas:memory:embed-backfill --stale --json',
                'writes_live_default_model' => false,
                'requires_dual_read_ledger' => true,
            ],
            'rollback' => [
                'handle' => 'maxa04:restore-current-semantic-rag-model',
                'description' => 'Restore ATLAS_SEMANTIC_RAG_MODEL to the prior model and discard jina-v3 shadow rows before any operator promotion.',
                'default_model_unchanged' => true,
            ],
            'default_promoted' => false,
            'applied_to_live' => false,
            'pending_window' => self::PENDING_WINDOW,
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
        $status = $normalized === [] ? 'insufficient_signal' : 'pending_window';

        return [
            'schema_version' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'slice' => 'MAXA-04',
            'status' => $status,
            'reason' => $normalized === [] ? 'no_dual_read_cases' : null,
            'window_basis' => $windowBasis,
            'summary' => $summary,
            'cases' => $normalized,
            'ab_green_claimed' => false,
            'promotion' => $this->promotionBlock(),
            'rollback' => $this->plan()['rollback'],
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
            'schema_version' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'series' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'slice' => 'MAXA-04',
            'recorded_at' => now()->toJSON(),
            'status' => $report['status'],
            'window_basis' => $report['window_basis'],
            'summary' => $report['summary'],
            'ab_green_claimed' => false,
            'promotion' => $report['promotion'],
            'dual_read_hash' => hash('sha256', (string) json_encode($report['cases'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
        ];

        ($ledger ?? new Maxa04JinaV3DualReadLedger)->append($receipt);

        return $report + ['ledger_recorded' => true, 'dual_read_hash' => $receipt['dual_read_hash']];
    }

    /** @param list<array<string,mixed>> $cases @return list<array<string,mixed>> */
    private function normalizeCases(array $cases): array
    {
        $normalized = [];
        foreach ($cases as $case) {
            $queryId = AiValueNormalizer::trimmedString($case['query_id'] ?? $case['id'] ?? '');
            if ($queryId === '') {
                continue;
            }

            $normalized[] = [
                'query_id' => $queryId,
                'current_recall_at_5' => $this->unitOrNull($case['current_recall_at_5'] ?? null),
                'candidate_recall_at_5' => $this->unitOrNull($case['candidate_recall_at_5'] ?? null),
                'current_precision_at_5' => $this->unitOrNull($case['current_precision_at_5'] ?? null),
                'candidate_precision_at_5' => $this->unitOrNull($case['candidate_precision_at_5'] ?? null),
                'targets_available' => max(0, (int) ($case['targets_available'] ?? 0)),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $cases @return array<string,mixed> */
    private function summary(array $cases): array
    {
        $count = count($cases);
        $targets = array_sum(array_map(static fn (array $case): int => (int) $case['targets_available'], $cases));

        return [
            'cases' => $count,
            'targets_available' => $targets,
            'current_recall_at_5_mean' => $this->mean($cases, 'current_recall_at_5'),
            'candidate_recall_at_5_mean' => $this->mean($cases, 'candidate_recall_at_5'),
            'current_precision_at_5_mean' => $this->mean($cases, 'current_precision_at_5'),
            'candidate_precision_at_5_mean' => $this->mean($cases, 'candidate_precision_at_5'),
        ];
    }

    /** @param array<string,mixed> $summary */
    private function windowBasis(array $summary): string
    {
        if ((int) $summary['cases'] <= 0) {
            return 'no_dual_read_cases';
        }

        $candidateRecall = AiValueNormalizer::finiteFloatOrNull($summary['candidate_recall_at_5_mean'] ?? null);
        $currentRecall = AiValueNormalizer::finiteFloatOrNull($summary['current_recall_at_5_mean'] ?? null);
        $candidatePrecision = AiValueNormalizer::finiteFloatOrNull($summary['candidate_precision_at_5_mean'] ?? null);
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
            'allowed' => false,
            'basis' => 'pending_window',
            'default_promoted' => false,
            'live_flip_performed' => false,
            'reason' => 'MAXA-04 only lands the dual-read/re-embed mechanism; promotion requires a later operator-reviewed benchmark window.',
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
