<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Illuminate\Support\Str;

class PredictiveFailureSelector
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_failure.selector.v1';

    public function __construct(
        private readonly DreyfusOverlayRepository $dreyfus,
        private readonly FailureSignatureRepository $failures,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function select(string $nodeOrTopic, string $domain = 'learning'): array
    {
        $nodeId = $this->normalizeNodeId($nodeOrTopic);
        $overlay = $this->dreyfus->find($nodeId, $domain);
        $recentFailures = $this->failures->recent($domain, 60);
        $nearestFailure = $recentFailures[0] ?? null;
        $stage = (int) ($overlay['current_level'] ?? 2);
        $confidence = (float) ($overlay['confidence'] ?? 0.45);
        $decayScore = $this->decayScore($overlay);
        $kgGapScore = $overlay ? max(0.0, 1.0 - $confidence) : 0.7;
        $failureSignal = $nearestFailure ? min(1.0, 0.45 + ((int) ($nearestFailure['recurrence_count'] ?? 1) * 0.12)) : 0.35;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'selected',
            'target_knowledge_node_id' => $nodeId,
            'topic' => $nodeOrTopic !== '' ? $nodeOrTopic : $nodeId,
            'domain' => $domain,
            'signals_used' => [
                'kg_gap_score' => round($kgGapScore, 3),
                'decay_score' => $decayScore,
                'dreyfus_stage' => max(1, min(5, $stage)),
                'dreyfus_confidence' => round($confidence, 3),
                'similar_failure_signatures' => $nearestFailure ? [[
                    'signature_key' => $nearestFailure['signature_key'] ?? null,
                    'category' => $nearestFailure['category'] ?? null,
                    'sub_cause' => $nearestFailure['sub_cause'] ?? null,
                    'recurrence_count' => $nearestFailure['recurrence_count'] ?? 1,
                ]] : [],
                'failure_history_signal' => round($failureSignal, 3),
                'cognitive_load' => ['level' => 'normal'],
            ],
            'predicted_failure_signature_key' => $nearestFailure['signature_key'] ?? null,
            'source_type' => $nearestFailure ? 'personal_derived' : 'canonical_library',
        ];
    }

    private function normalizeNodeId(string $nodeOrTopic): string
    {
        $nodeOrTopic = trim($nodeOrTopic);
        if (Str::isUuid($nodeOrTopic)) {
            return $nodeOrTopic;
        }

        return $this->dreyfus->nodeIdForTopic($nodeOrTopic !== '' ? $nodeOrTopic : 'unknown');
    }

    /**
     * @param  array<string,mixed>|null  $overlay
     */
    private function decayScore(?array $overlay): float
    {
        if (! $overlay || empty($overlay['next_validation_at'])) {
            return 0.65;
        }

        $daysOverdue = max(0, now()->diffInDays($overlay['next_validation_at'], false) * -1);

        return round(min(1.0, 0.25 + ($daysOverdue / 30)), 3);
    }
}
