<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiSurfaceHandoff extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'session_id',
        'from_surface',
        'to_surface',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'session_id' => 'string',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
