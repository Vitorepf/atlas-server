<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;

class OperatorProfileConflictResolver
{
    /**
     * @return array<string,mixed>
     */
    public function detect(OperatorProfileItem $item): array
    {
        $conflicts = OperatorProfileItem::query()
            ->where('operator_id', $item->operator_id)
            ->where('profile_key', $item->profile_key)
            ->where('id', '!=', $item->id)
            ->where('status', OperatorProfileItem::STATUS_ACTIVE)
            ->get()
            ->map(fn (OperatorProfileItem $other): array => [
                'id' => $other->id,
                'summary' => $other->summary,
                'confidence' => $other->confidence,
                'scope_type' => $other->scope_type,
                'scope_id' => $other->scope_id,
            ])
            ->all();

        return [
            'item_id' => $item->id,
            'profile_key' => $item->profile_key,
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
            'resolution' => count($conflicts) === 0 ? 'none' : 'prefer_scope_and_highest_confidence',
        ];
    }
}
