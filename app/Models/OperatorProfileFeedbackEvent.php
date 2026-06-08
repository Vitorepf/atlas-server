<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorProfileFeedbackEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'operator_profile_item_id',
        'trace_id',
        'session_id',
        'feedback_action',
        'feedback_score',
        'outcome',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'feedback_score' => 'float',
            'outcome' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function profileItem(): BelongsTo
    {
        return $this->belongsTo(OperatorProfileItem::class, 'operator_profile_item_id');
    }
}
