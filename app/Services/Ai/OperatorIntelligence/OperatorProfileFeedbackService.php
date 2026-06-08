<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileFeedbackEvent;
use App\Models\OperatorProfileItem;
use Illuminate\Support\Carbon;

class OperatorProfileFeedbackService
{
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
}
