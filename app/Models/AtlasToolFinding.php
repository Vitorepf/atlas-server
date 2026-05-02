<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasToolFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'tool_run_id',
        'rule_id',
        'title',
        'message',
        'severity',
        'confidence',
        'file_path',
        'line',
        'end_line',
        'fingerprint',
        'blocks_resolved',
        'waiver_id',
        'status',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'line' => 'integer',
            'end_line' => 'integer',
            'blocks_resolved' => 'boolean',
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasToolRun::class, 'tool_run_id');
    }
}
