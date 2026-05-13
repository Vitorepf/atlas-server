<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class AtlasProgrammingReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id',
        'result',
        'summary',
        'risk_notes',
        'decided_by',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(AtlasProgrammingWorkItem::class, 'work_item_id');
    }
}
