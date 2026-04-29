<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorPromotion extends Model
{
    use HasUuids;

    protected $fillable = [
        'behavior_id',
        'target_object_type',
        'target_object_id',
        'promoted_at',
        'days_tracked_at_promotion',
        'adherence_at_promotion',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'promoted_at' => 'immutable_datetime',
            'days_tracked_at_promotion' => 'integer',
            'adherence_at_promotion' => 'float',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(Behavior::class);
    }
}
