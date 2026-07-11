<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;

final class AtlasContextFeedbackSignalPolicy
{
    public const TOTAL_EVENT_FLOOR = 20;

    public const SOURCE_BUCKET_MIN_EVENTS = 2;

    /** @var list<string> */
    private const EXCLUDED_ATTRIBUTION_QUALITIES = [
        'transcript_inferred',
    ];

    public function isMeasuredAggregateEligible(AiRagFeedbackEvent $event): bool
    {
        return $this->isMeasured($event)
            && ! in_array($this->attributionQuality($event), self::EXCLUDED_ATTRIBUTION_QUALITIES, true);
    }

    public function isMeasured(AiRagFeedbackEvent $event): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        foreach ([
            'measured',
            'payload.measured',
            'payload.payload.measured',
            'context_roi.measured',
            'payload.context_roi.measured',
            'payload.payload.context_roi.measured',
            'context_ref_attribution.measured',
            'payload.context_ref_attribution.measured',
            'payload.payload.context_ref_attribution.measured',
        ] as $path) {
            $value = data_get($payload, $path);
            if ($value !== null) {
                return (bool) $value;
            }
        }

        return false;
    }

    public function attributionQuality(AiRagFeedbackEvent $event): string
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        foreach ([
            'attribution_quality',
            'payload.attribution_quality',
            'payload.payload.attribution_quality',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return strtolower(trim((string) $value));
            }
        }

        return 'low';
    }

    public function sourceTypeFromRef(string $ref): ?string
    {
        $ref = strtolower(trim($ref));
        if ($ref === '') {
            return null;
        }
        if (str_contains($ref, ':')) {
            $ref = strtok($ref, ':') ?: $ref;
        }

        return match ($ref) {
            'code', 'code_intelligence', 'context_ref', 'symbol', 'route', 'migration', 'test' => 'code',
            'graph', 'graph_retrieval', 'reality_graph', 'aurg' => 'graph',
            'memory', 'memory_signals', 'semantic', 'semantic_candidate', 'vector_retrieval', 'decision', 'technical_context', 'compounding_memory' => 'memory',
            default => null,
        };
    }
}
