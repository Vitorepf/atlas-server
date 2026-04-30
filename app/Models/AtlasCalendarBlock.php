<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasCalendarBlock extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'block_date',
        'timezone',
        'title',
        'starts_at',
        'ends_at',
        'source',
        'source_ref',
        'task_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'block_date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }
}
