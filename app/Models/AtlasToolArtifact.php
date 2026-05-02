<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasToolArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'tool_run_id',
        'type',
        'path',
        'filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'is_redacted',
        'preview_json',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_redacted' => 'boolean',
            'preview_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasToolRun::class, 'tool_run_id');
    }
}
