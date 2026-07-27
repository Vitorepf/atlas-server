<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileFeedbackEvent;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\Support\OperatorProfileFeedbackSupport;
use Illuminate\Support\Carbon;

class OperatorProfileFeedbackService
{
    public const BEHAVIOR_DENOMINATOR_MIN = 10;

    private const DEMOTION_CONFIDENCE_FLOOR = 0.25;

    /**
     * @param  array<string,mixed>  $outcome
     */
    public function record(OperatorProfileItem|string $item, string $action, ?float $score = null, array $outcome = []): OperatorProfileFeedbackEvent
    {
        $profileItem = $item instanceof OperatorProfileItem ? $item : OperatorProfileItem::query()->findOrFail($item);

        return OperatorProfileFeedbackEvent::query()->create([
            'operator_profile_item_id' => $profileItem->id,
            'trace_id' => is_string($outcome['trace_id'] ?? null) ? $outcome['trace_id'] : null,
            'session_id' => is_string($outcome['session_id'] ?? null) ? $outcome['session_id'] : null,
            'feedback_action' => $action,
            'feedback_score' => $score,
            'outcome' => $outcome,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function previewBehaviorAdjustment(OperatorProfileItem|string $item): array
    {
        $profileItem = $item instanceof OperatorProfileItem ? $item : OperatorProfileItem::query()->findOrFail($item);
        $counts = $this->behaviorCounts($profileItem);
        $samples = $counts['accepted'] + $counts['overrides'] + $counts['ignored'];

        $payload = [
            'schema_version' => 'atlas.operator_profile_behavior_adjustment.v1',
            'item_id' => (string) $profileItem->id,
            'status' => $samples < self::BEHAVIOR_DENOMINATOR_MIN ? 'insufficient_sample' : 'ready',
            'denominator_min' => self::BEHAVIOR_DENOMINATOR_MIN,
            'samples' => $samples,
            'accepted' => $counts['accepted'],
            'overrides' => $counts['overrides'],
            'ignored' => $counts['ignored'],
            'declared_confidence' => (float) $profileItem->confidence,
            'author_judge_boundary' => 'operator_behavior_is_judge',
        ];

        if ($samples >= self::BEHAVIOR_DENOMINATOR_MIN) {
            $payload['behavior_confidence'] = $this->confidenceFromBehavior(
                (float) $profileItem->confidence,
                $counts['accepted'],
                $counts['overrides'],
                $counts['ignored'],
            );
            $payload['would_demote'] = $payload['behavior_confidence'] < self::DEMOTION_CONFIDENCE_FLOOR;
        }

        return $payload;
    }

    public function confidenceFromBehavior(float $current, int $accepted, int $overrides, int $ignored): float
    {
        $samples = max(1, $accepted + $overrides + $ignored);
        $delta = (($accepted * 0.20) - ($overrides * 0.20) - ($ignored * 0.12)) / $samples;

        return round(min(0.99, max(0.01, $current + $delta)), 4);
    }

    /**
     * @return array<string,mixed>
     */
    public function applyBehaviorAdjustment(OperatorProfileItem|string $item): array
    {
        $profileItem = $item instanceof OperatorProfileItem ? $item : OperatorProfileItem::query()->findOrFail($item);
        $preview = $this->previewBehaviorAdjustment($profileItem);
        if (($preview['status'] ?? '') !== 'ready') {
            return $preview;
        }

        $previousConfidence = (float) $profileItem->confidence;
        $previousStatus = (string) $profileItem->status;
        $newConfidence = (float) $preview['behavior_confidence'];
        $newStatus = $newConfidence < self::DEMOTION_CONFIDENCE_FLOOR ? OperatorProfileItem::STATUS_PAUSED : $previousStatus;

        $profileItem->forceFill([
            'confidence' => $newConfidence,
            'status' => $newStatus,
        ])->save();

        $reverseHandle = $this->encodeReverseHandle([
            'schema_version' => 'atlas.operator_profile_behavior_reverse.v1',
            'item_id' => (string) $profileItem->id,
            'previous_confidence' => $previousConfidence,
            'previous_status' => $previousStatus,
            'applied_confidence' => $newConfidence,
            'applied_status' => $newStatus,
            'applied_at' => Carbon::now()->toIso8601String(),
        ]);

        return array_merge($preview, [
            'status' => 'applied',
            'previous_confidence' => $previousConfidence,
            'new_confidence' => $newConfidence,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reverse_handle' => $reverseHandle,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function revertBehaviorAdjustment(string $reverseHandle): array
    {
        $payload = $this->decodeReverseHandle($reverseHandle);
        $item = OperatorProfileItem::query()->findOrFail((string) $payload['item_id']);
        $item->forceFill([
            'confidence' => (float) $payload['previous_confidence'],
            'status' => (string) $payload['previous_status'],
        ])->save();

        return [
            'status' => 'reverted',
            'item_id' => (string) $item->id,
            'restored_confidence' => (float) $payload['previous_confidence'],
            'restored_status' => (string) $payload['previous_status'],
        ];
    }

    /**
     * @return array{accepted:int,overrides:int,ignored:int}
     */
    private function behaviorCounts(OperatorProfileItem $item): array
    {
        $counts = ['accepted' => 0, 'overrides' => 0, 'ignored' => 0];
        foreach ($item->feedbackEvents()->get(['feedback_action']) as $event) {
            $action = strtolower((string) $event->feedback_action);
            if (in_array($action, ['accepted', 'accept', 'used', 'applied'], true)) {
                $counts['accepted']++;
            } elseif (in_array($action, ['override', 'overridden', 'rejected', 'reject'], true)) {
                $counts['overrides']++;
            } elseif (in_array($action, ['ignored', 'ignore'], true)) {
                $counts['ignored']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encodeReverseHandle(array $payload): string
    {
        return OperatorProfileFeedbackSupport::encodeReverseHandle($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeReverseHandle(string $handle): array
    {
        return OperatorProfileFeedbackSupport::decodeReverseHandle($handle);
    }
}
