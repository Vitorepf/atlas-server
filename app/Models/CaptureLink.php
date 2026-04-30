<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptureLink extends Model
{
    use HasUuids;

    protected $fillable = [
        'capture_id',
        'target_type',
        'target_id',
        'target_title',
        'relation_type',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }
}
