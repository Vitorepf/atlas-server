<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiInboxItem;
use App\Models\AiPerformanceRecommendation;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Support\DatabaseTableAvailability;

class RecommendationInboxEmitter
{
    public function __construct(
        private readonly AtlasInboxService $inbox,
    ) {}

    public function emit(AiPerformanceRecommendation $recommendation): ?AiInboxItem
    {
        if (! DatabaseTableAvailability::has('ai_inbox_items')) {
            return null;
        }

        return $this->inbox->create([
            'user_id' => $recommendation->user_id,
            'type' => 'insight',
            'category' => 'atlas_ai_recommendation',
            'severity' => $this->severity($recommendation),
            'title' => $this->title($recommendation),
            'summary' => $this->summary($recommendation),
            'body' => $this->body($recommendation),
            'source_type' => 'ai_performance_recommendation',
            'source_id' => $recommendation->id,
            'initiator' => 'atlas',
            'dedupe_key' => 'atlas-ai-recommendation:'.$recommendation->id,
            'available_actions' => [
                ['id' => 'acknowledge_recommendation', 'label' => 'Reconhecer', 'style' => 'default'],
                ['id' => 'apply_recommendation', 'label' => 'Marcar aplicada', 'style' => 'primary', 'requires_confirm' => true],
                ['id' => 'reject_recommendation', 'label' => 'Rejeitar', 'style' => 'destructive', 'requires_confirm' => true],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'snooze', 'label' => 'Adiar', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'payload' => [
                'category' => 'atlas_ai_recommendation',
                'insight_kind' => 'performance_recommendation',
                'recommendation' => $this->payload($recommendation),
            ],
            'push_policy' => [
                'send' => 'immediate',
                'reason' => 'atlas_ai_performance_recommendation',
                'force' => false,
            ],
            'priority_score' => $recommendation->priority_score,
            'confidence_score' => (float) data_get($recommendation->expected_impact, 'confidence', 0.75),
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function title(AiPerformanceRecommendation $recommendation): string
    {
        return 'Atlas: recomendacao para '.$recommendation->target_metric;
    }

    private function summary(AiPerformanceRecommendation $recommendation): string
    {
        $dimensions = collect((array) $recommendation->target_dimension)
            ->map(fn (mixed $value, string $key): string => "{$key}={$value}")
            ->implode(', ');

        return trim(sprintf(
            '%s em %s. Impacto esperado: %s.',
            $recommendation->kind,
            $dimensions !== '' ? $dimensions : 'escopo global',
            (string) data_get($recommendation->expected_impact, 'rationale', 'melhorar a metrica alvo'),
        ));
    }

    private function body(AiPerformanceRecommendation $recommendation): string
    {
        return implode("\n\n", array_filter([
            'Recomendacao criada pelo engine de performance do Atlas.',
            'Metrica alvo: '.$recommendation->target_metric,
            'Dimensao: '.json_encode($recommendation->target_dimension, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Baseline: '.json_encode($recommendation->baseline_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Impacto esperado: '.json_encode($recommendation->expected_impact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Janela de medicao apos aplicar: '.(int) $recommendation->measurement_window_days.' dia(s).',
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(AiPerformanceRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'state' => $recommendation->state,
            'kind' => $recommendation->kind,
            'target_metric' => $recommendation->target_metric,
            'target_dimension' => $recommendation->target_dimension,
            'expected_impact' => $recommendation->expected_impact,
            'baseline_snapshot' => $recommendation->baseline_snapshot,
            'measurement_window_days' => $recommendation->measurement_window_days,
            'priority_score' => $recommendation->priority_score,
        ];
    }

    private function severity(AiPerformanceRecommendation $recommendation): string
    {
        return (int) $recommendation->priority_score >= 75 ? 'warning' : 'info';
    }
}
