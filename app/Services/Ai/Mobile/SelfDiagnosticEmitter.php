<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiQualityEvaluation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SelfDiagnosticEmitter
{
    public function __construct(
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
    ) {
    }

    /**
     * @return array{emitted:bool,item_id:?string,reason:string,metrics:array<string,mixed>}
     */
    public function run(bool $dryRun = false): array
    {
        if (! $this->tablesReady()) {
            return $this->result(false, null, 'tables_missing', []);
        }

        $metrics = $this->metrics();
        $diagnosis = $this->diagnose($metrics);
        if (! $diagnosis['should_emit']) {
            return $this->result(false, null, (string) $diagnosis['reason'], $metrics);
        }

        if ($ignored = $this->ignoredItem((string) $diagnosis['category'])) {
            return $this->result(false, $ignored->id, 'ignored_30d_active', [
                ...$metrics,
                'ignored_until' => data_get($ignored->payload, 'ignored_until'),
            ]);
        }

        if ($existing = $this->existingActiveItem((string) $diagnosis['category'])) {
            return $this->result(false, $existing->id, 'deduped_active_item', $metrics);
        }

        if ($dryRun) {
            return $this->result(false, null, 'dry_run_would_emit', [
                ...$metrics,
                'diagnosis' => $diagnosis,
            ]);
        }

        $bundle = $this->bundles->create([
            'purpose' => 'self_diagnostic',
            'title' => 'Self-diagnostic Atlas',
            'summary' => (string) $diagnosis['summary'],
            'body_for_thread' => $this->threadBody($metrics, $diagnosis),
            'metric_refs' => $this->metricRefs($metrics),
            'raw_payload' => [
                'metrics' => $metrics,
                'diagnosis' => $diagnosis,
            ],
            'expires_at' => now()->addDays(14),
        ]);

        $item = $this->inbox->create([
            'type' => 'self_diagnostic',
            'category' => (string) $diagnosis['category'],
            'severity' => (string) $diagnosis['severity'],
            'title' => (string) $diagnosis['title'],
            'summary' => (string) $diagnosis['summary'],
            'body' => (string) $diagnosis['body'],
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => 'self_diagnostic:'.(string) $diagnosis['category'],
            'confidence_score' => (float) $diagnosis['confidence'],
            'payload' => [
                'category' => $diagnosis['category'],
                'observation' => $diagnosis['observation'],
                'hypothesis' => $diagnosis['hypothesis'],
                'proposed_fix' => $diagnosis['proposed_fix'],
                'metrics' => $metrics,
            ],
            'push_policy' => ['send' => 'immediate', 'reason' => 'self_diagnostic'],
            'priority_score' => (string) $diagnosis['severity'] === 'critical' ? 90 : 75,
            'expires_at' => now()->addDays(14),
        ]);

        return $this->result(true, $item->id, 'emitted', $metrics);
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('ai_quality_evaluations')
            && Schema::hasTable('ai_context_bundles')
            && Schema::hasTable('ai_inbox_items');
    }

    /**
     * @return array<string,mixed>
     */
    private function metrics(): array
    {
        $recentDays = max(1, (int) config('atlas.mobile.self_diagnostic.recent_days', 7));
        $baselineDays = max($recentDays + 1, (int) config('atlas.mobile.self_diagnostic.baseline_days', 30));
        $recentSince = now()->subDays($recentDays);
        $baselineSince = now()->subDays($baselineDays);

        $recentQuery = AiQualityEvaluation::query()->where('created_at', '>=', $recentSince);
        $baselineQuery = AiQualityEvaluation::query()
            ->where('created_at', '>=', $baselineSince)
            ->where('created_at', '<', $recentSince);

        $recentCount = (clone $recentQuery)->count();
        $baselineCount = (clone $baselineQuery)->count();
        $recentFailed = (clone $recentQuery)->where('status', 'failed')->count();
        $baselineFailed = (clone $baselineQuery)->where('status', 'failed')->count();

        return [
            'recent_days' => $recentDays,
            'baseline_days' => $baselineDays,
            'recent_count' => $recentCount,
            'baseline_count' => $baselineCount,
            'recent_avg_score' => $recentCount > 0 ? round((float) (clone $recentQuery)->avg('score'), 2) : null,
            'baseline_avg_score' => $baselineCount > 0 ? round((float) (clone $baselineQuery)->avg('score'), 2) : null,
            'recent_failed_rate' => $recentCount > 0 ? round($recentFailed / $recentCount, 4) : null,
            'baseline_failed_rate' => $baselineCount > 0 ? round($baselineFailed / $baselineCount, 4) : null,
            'computed_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    private function diagnose(array $metrics): array
    {
        $minRecent = max(1, (int) config('atlas.mobile.self_diagnostic.min_recent_samples', 4));
        $minBaseline = max(1, (int) config('atlas.mobile.self_diagnostic.min_baseline_samples', 6));
        if ((int) $metrics['recent_count'] < $minRecent || (int) $metrics['baseline_count'] < $minBaseline) {
            return ['should_emit' => false, 'reason' => 'insufficient_samples'];
        }

        $recentAvg = $this->float($metrics['recent_avg_score']);
        $baselineAvg = $this->float($metrics['baseline_avg_score']);
        $recentFailedRate = $this->float($metrics['recent_failed_rate']);
        $baselineFailedRate = $this->float($metrics['baseline_failed_rate']);
        if ($recentAvg === null || $baselineAvg === null || $recentFailedRate === null || $baselineFailedRate === null) {
            return ['should_emit' => false, 'reason' => 'missing_metrics'];
        }

        $scoreDrop = round($baselineAvg - $recentAvg, 2);
        $failedRateIncrease = round($recentFailedRate - $baselineFailedRate, 4);
        $dropThreshold = (float) config('atlas.mobile.self_diagnostic.score_drop_threshold', 12);
        $failureThreshold = (float) config('atlas.mobile.self_diagnostic.failure_rate_increase_threshold', 0.2);

        if ($scoreDrop < $dropThreshold && $failedRateIncrease < $failureThreshold) {
            return ['should_emit' => false, 'reason' => 'no_confirmed_regression'];
        }

        $category = $scoreDrop >= $dropThreshold ? 'quality_score_regression' : 'quality_failure_rate_regression';
        $confidence = min(0.95, 0.7 + max($scoreDrop / 100, $failedRateIncrease / 2));
        $confidenceThreshold = (float) config('atlas.mobile.self_diagnostic.confidence_threshold', 0.7);
        if ($confidence < $confidenceThreshold) {
            return [
                'should_emit' => false,
                'reason' => 'below_confidence_threshold',
                'confidence' => round($confidence, 3),
                'confidence_threshold' => $confidenceThreshold,
            ];
        }

        $severity = $scoreDrop >= ($dropThreshold * 1.75) || $failedRateIncrease >= ($failureThreshold * 1.75)
            ? 'critical'
            : 'warning';

        $observation = "Score medio recente {$recentAvg} contra baseline {$baselineAvg}; queda {$scoreDrop}. Taxa de falha recente {$recentFailedRate} contra {$baselineFailedRate}.";
        $hypothesis = $category === 'quality_score_regression'
            ? 'A qualidade media caiu de forma suficiente para sugerir regressao em prompt, contexto, roteamento ou provider.'
            : 'A proporcao de respostas falhando aumentou de forma suficiente para sugerir regressao operacional.';
        $proposedFix = 'Abrir Atlas com contexto, revisar exemplos recentes com menor score, checar flags dominantes e ajustar prompt/contexto/roteamento antes de automatizar qualquer correcao.';

        return [
            'should_emit' => true,
            'reason' => 'confirmed_regression',
            'category' => $category,
            'severity' => $severity,
            'confidence' => round($confidence, 3),
            'title' => 'Atlas detectou regressao de desempenho',
            'summary' => "Queda confirmada: score {$baselineAvg} -> {$recentAvg}.",
            'body' => implode("\n", [
                'O que encontrei: '.$observation,
                'Hipotese: '.$hypothesis,
                'Proposta: '.$proposedFix,
                'Vale a pena agir: sim, porque o sinal passou do threshold configurado e tem amostras suficientes.',
            ]),
            'observation' => $observation,
            'hypothesis' => $hypothesis,
            'proposed_fix' => $proposedFix,
        ];
    }

    private function existingActiveItem(string $category): ?AiInboxItem
    {
        return AiInboxItem::query()
            ->where('dedupe_key', 'self_diagnostic:'.$category)
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->latest('created_at')
            ->first();
    }

    private function ignoredItem(string $category): ?AiInboxItem
    {
        $item = AiInboxItem::query()
            ->where('dedupe_key', 'self_diagnostic:'.$category)
            ->latest('updated_at')
            ->first();

        $ignoredUntil = data_get($item?->payload, 'ignored_until');
        if (! is_string($ignoredUntil) || trim($ignoredUntil) === '') {
            return null;
        }

        try {
            return Carbon::parse($ignoredUntil)->isFuture() ? $item : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $diagnosis
     */
    private function threadBody(array $metrics, array $diagnosis): string
    {
        return implode("\n\n", [
            'Atlas abriu este self-diagnostic porque detectou regressao confirmada no proprio desempenho.',
            (string) $diagnosis['body'],
            'Metricas brutas: '.json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Objetivo da conversa: decidir se a hipotese faz sentido, qual ajuste vale a pena e se devemos criar uma proposta executavel.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<int,array<string,mixed>>
     */
    private function metricRefs(array $metrics): array
    {
        return collect($metrics)
            ->map(fn (mixed $value, string $key): array => ['name' => $key, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{emitted:bool,item_id:?string,reason:string,metrics:array<string,mixed>}
     */
    private function result(bool $emitted, ?string $itemId, string $reason, array $metrics): array
    {
        return [
            'emitted' => $emitted,
            'item_id' => $itemId,
            'reason' => $reason,
            'metrics' => $metrics,
        ];
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
