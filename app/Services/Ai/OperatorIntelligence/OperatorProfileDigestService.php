<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorLearningSignal;
use App\Models\OperatorProfileFeedbackEvent;
use App\Models\OperatorProfileItem;
use App\Models\OperatorProfileSnapshot;

class OperatorProfileDigestService
{
    /**
     * @return array<string,mixed>
     */
    public function digest(string $operatorId, int $days = 7, bool $persistSnapshot = true): array
    {
        $since = now()->subDays(max(1, $days));
        $items = OperatorProfileItem::query()
            ->where('operator_id', $operatorId)
            ->active()
            ->orderByDesc('confidence')
            ->get();
        $pending = OperatorLearningCandidate::query()
            ->where('operator_id', $operatorId)
            ->pending()
            ->count();
        $recentSignals = OperatorLearningSignal::query()
            ->where('operator_id', $operatorId)
            ->where('created_at', '>=', $since)
            ->count();
        $feedback = OperatorProfileFeedbackEvent::query()
            ->whereIn('operator_profile_item_id', $items->pluck('id')->all())
            ->where('created_at', '>=', $since)
            ->count();

        $summary = sprintf(
            "Operator profile: %d active items, %d pending candidates, %d recent signals, %d recent feedback events.",
            $items->count(),
            $pending,
            $recentSignals,
            $feedback,
        );

        $payload = [
            'schema_version' => 'atlas.operator_profile_digest.v1',
            'operator_id' => $operatorId,
            'window_days' => $days,
            'summary' => $summary,
            'counts' => [
                'active_profile_items' => $items->count(),
                'pending_candidates' => $pending,
                'recent_signals' => $recentSignals,
                'recent_feedback_events' => $feedback,
            ],
            'top_profile_items' => $items->take(12)->map(fn (OperatorProfileItem $item): array => [
                'id' => $item->id,
                'taxonomy_item_id' => $item->taxonomy_item_id,
                'profile_key' => $item->profile_key,
                'summary' => $item->summary,
                'confidence' => $item->confidence,
                'privacy_class' => $item->privacy_class,
                'automation_level' => $item->automation_level,
            ])->all(),
            'safety' => [
                'raw_private_context_included' => false,
                'repo_projection_safe' => false,
            ],
        ];

        if ($persistSnapshot) {
            $snapshot = OperatorProfileSnapshot::query()->create([
                'operator_id' => $operatorId,
                'snapshot_kind' => 'digest',
                'summary' => $summary,
                'profile_hash' => hash('sha256', json_encode($payload['top_profile_items'], JSON_THROW_ON_ERROR)),
                'included_item_ids' => $items->take(50)->pluck('id')->values()->all(),
                'metadata' => [
                    'window_days' => $days,
                    'counts' => $payload['counts'],
                ],
            ]);
            $payload['snapshot_id'] = $snapshot->id;
        }

        return $payload;
    }
}
